<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Modules;
use EasyAudit\Core\Scan\Util\Types;
use EasyAudit\Service\ClassToProxy;
use EasyAudit\Service\CliWriter;

/**
 * Class SpecificClassInjection
 *
 * Detects problematic class injections in constructors.
 * In Magento 2, certain classes should never be injected directly:
 * - Collections must use Factory
 * - Repositories must use Interface
 * - Models implementing API interfaces should inject the interface instead
 * - Resource Models should be replaced with Repository pattern
 *
 * This helps maintain proper abstraction, allows preferences to work correctly,
 * and follows Magento 2 coding standards.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class SpecificClassInjection extends AbstractProcessor
{
    /**
     * Rule configurations for each violation type
     */
    private const RULE_CONFIGS = [
        'collection' => [
            'ruleId' => 'collectionMustUseFactory',
            'name' => 'Collection Must Use Factory',
            'shortDescription' => 'Collections must not be injected directly',
            'longDescription' => 'Detects direct collection class injection in constructors.
Impact: The collection is instantiated at construction time, loading its state eagerly. This wastes resources when the collection is not always needed and prevents fresh queries on subsequent calls.
Why change: The factory pattern exists specifically to control collection instantiation and ensure each usage gets a clean query builder.
How to fix: Inject the CollectionFactory instead and call create() when the collection is actually needed.',
            'label' => 'Collections without factory',
            'severity' => 'high',
        ],
        'repository' => [
            'ruleId' => 'repositoryMustUseInterface',
            'name' => 'Repository Must Use Interface',
            'shortDescription' => 'Repositories must use interface injection',
            'longDescription' => 'Detects concrete repository class injection instead of its interface.
Impact: DI preferences on the repository interface are ignored. Other modules cannot substitute the implementation, and unit tests cannot mock it via interface.
Why change: Repositories are designed to be consumed through their interface. Concrete injection breaks this contract and prevents the DI system from working as intended.
How to fix: Type the constructor parameter to the repository interface (e.g., ProductRepositoryInterface).',
            'label' => 'Repositories without interface',
            'severity' => 'high',
        ],
        'modelWithInterface' => [
            'ruleId' => 'modelUseApiInterface',
            'name' => 'Model Should Use API Interface',
            'shortDescription' => 'Models with API interfaces should inject the interface',
            'longDescription' => 'Detects concrete model injection when an API data interface is available.
Impact: Preferences on the API interface are ignored, and test doubles cannot be substituted. The consumer is coupled to the persistence layer instead of the data contract.
Why change: API interfaces define the public data contract. Injecting the concrete model bypasses this abstraction and tightens coupling unnecessarily.
How to fix: Inject the API data interface instead of the concrete model class.',
            'label' => 'Models should use API interface',
            'severity' => 'high',
        ],
        'resourceModel' => [
            'ruleId' => 'noResourceModelInjection',
            'name' => 'Resource Model Should Not Be Injected',
            'shortDescription' => 'Resource models should use repository pattern',
            'longDescription' => 'Detects direct resource model injection in constructors.
Impact: Resource models represent the raw database layer. Injecting them directly couples business logic to the persistence implementation, bypassing the repository\'s validation and event dispatch.
Why change: The repository pattern provides a clean separation between business logic and persistence. Direct resource model access breaks this boundary.
How to fix: Use the repository pattern instead of direct resource model injection.',
            'label' => 'Resource models injected',
            'severity' => 'medium',
        ],
        'statefulModel' => [
            'ruleId' => 'statefulModelInjection',
            'name' => 'Stateful Model Injected Directly',
            'shortDescription' => 'Model extending AbstractModel is stateful and should use a Factory.',
            'longDescription' => 'Detects direct injection of classes extending AbstractModel.
Impact: AbstractModel instances hold mutable state (loaded database rows). When injected directly, the same instance is shared across all callers, causing stale data and side effects.
Why change: Shared mutable state across a request leads to subtle bugs that are hard to trace and impossible to reproduce reliably in tests.
How to fix: Inject a Factory and call create() to get fresh instances when needed.',
            'label' => 'Stateful models without factory',
            'severity' => 'medium',
        ],
        'genericClass' => [
            'ruleId' => 'specificClassInjection',
            'name' => 'Specific Class Injection',
            'shortDescription' => 'Consider using factory, builder, or interface',
            'longDescription' => 'Detects concrete class injection where an interface or factory would be more appropriate.
Impact: Tight coupling to a specific implementation reduces substitutability and makes unit testing harder. This pattern accumulates across a codebase and increases the cost of refactoring.
Why change: Proper abstraction through interfaces and factories is a core Magento 2 design principle that enables DI preferences, testing, and extensibility.
How to fix: Use a factory, builder, or interface instead. This automatic scan may have false positives — verify manually.',
            'label' => 'Generic specific class injections',
            'severity' => 'medium',
        ],
    ];

    /**
     * Classes that are always ignored (legitimate specific class injections)
     */
    private const IGNORED_CLASSES = [
        'Magento\Eav\Model\Validator\Attribute\Backend',
        'Magento\Eav\Model\Config',
        'Magento\Eav\Model\Entity\Attribute\Config',
        'Magento\Cms\Model\Page',
        'Magento\Theme\Block\Html\Header\Logo',
        'Magento\Catalog\Model\Product\Visibility',
        'Magento\Catalog\Model\Product\Attribute\Source\Status',
        'Magento\Catalog\Model\Product\Type',
        'Magento\Catalog\Model\Product\Media\Config',
        'Magento\CatalogInventory\Model\Stock',
        'Magento\Sales\Model\Order\Status',
        'Magento\Sales\Model\Order\Config',
        'Magento\Sales\Model\Order\StatusFactory',
        'Magento\Customer\Model\Group',
        'Magento\Customer\Model\Customer\Attribute\Source\Group',
        'Magento\Store\Model\StoreManager',
        'Magento\Store\Model\Store',
        'Magento\Indexer\Model\Indexer\State',
        'Magento\Tax\Model\Calculation',
        'Magento\Tax\Model\Config',
        'Magento\Directory\Model\Currency',
        'Magento\Directory\Model\Country',
        'Magento\Directory\Model\Region',
        'Magento\Quote\Model\Quote\Address\RateResult\Method',
        'Magento\Quote\Model\Quote\Item\Option',
        'Magento\Config\Model\Config\Structure',
    ];

    /**
     * Substrings that indicate an argument should be ignored
     */
    private const IGNORED_SUBSTRINGS = [
        'Magento\Framework', 'Context', 'Session', 'Helper', 'Stdlib', 'Serializer', 'Generator',
        'AbstractModel', 'AbstractExtensibleModel',
    ];

    /**
     * Suffixes that indicate a legitimate injection pattern
     */
    private const LEGITIMATE_SUFFIXES = ['Interface', 'Factory', 'Provider', 'Resolver', 'Pool', 'Logger', 'Config', 'Builder', 'Emulation', 'Reader', 'Service', 'Settings'];


    /**
     * Results organized by violation type
     */
    private array $resultsByCategory = [];

    public function getIdentifier(): string
    {
        return 'specificClassInjection';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Specific Class Injection';
    }

    public function getMessage(): string
    {
        return 'Detects problematic direct class injections that should use factories, interfaces, or repositories instead.';
    }

    public function getLongDescription(): string
    {
        return 'Flags concrete class injections where interfaces or factories should be used.' . "\n"
            . 'Impact: Concrete injection tightly couples the dependent class to a specific '
            . 'implementation, reducing substitutability and making unit testing significantly harder. '
            . 'When concrete classes accumulate across a codebase, the cost of any refactoring '
            . 'increases.' . "\n"
            . 'Why change: Collections injected directly bypass the factory pattern designed to control '
            . 'instantiation. Repositories typed to concrete classes prevent preference-based '
            . 'substitution. Direct model injection ignores API interfaces meant for decoupling.' . "\n"
            . 'How to fix: Inject CollectionFactory instead of Collection. Type repositories to their '
            . 'interface (e.g., ProductRepositoryInterface). Inject API data interfaces instead of '
            . 'concrete models. Replace direct ResourceModel injection with the repository pattern.';
    }

    /**
     * Process PHP files to detect specific class injection issues
     */
    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        // Build class hierarchy for resolveClassToFile() used by statefulModel detection
        Classes::buildClassHierarchy($files['php']);

        foreach ($files['php'] as $file) {
            $fileContent = file_get_contents($file);
            if ($fileContent === false || !str_contains($fileContent, '__construct')) {
                continue;
            }
            $this->analyzeFile($file, $fileContent);
        }

        foreach (self::RULE_CONFIGS as $category => $config) {
            if (!empty($this->resultsByCategory[$category])) {
                CliWriter::resultLine(
                    $config['label'],
                    count($this->resultsByCategory[$category]),
                    $config['severity']
                );
            }
        }
    }

    /**
     * Analyze a single file for specific class injection issues
     */
    private function analyzeFile(string $file, string $fileContent): void
    {
        if (Classes::isFactoryClass($fileContent) || Classes::isCommandClass($fileContent)) {
            return;
        }

        if (Modules::isSetupDirectory($file)) {
            return;
        }

        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        if (empty($constructorParameters)) {
            return;
        }

        $importedClasses = Classes::parseImportedClasses($fileContent);
        $consolidatedParameters = Classes::consolidateParameters($constructorParameters, $importedClasses);

        if (empty($consolidatedParameters)) {
            return;
        }

        $className = Classes::extractClassName($fileContent);
        $constructorLine = Content::getLineNumber($fileContent, '__construct');
        // Use constructorLine - 1 so single-line constructors include the parameter on the same line
        $searchAfterLine = max(0, $constructorLine - 1);

        foreach ($consolidatedParameters as $paramName => $paramClass) {
            if (Classes::isParentPassthrough($fileContent, $paramName)) {
                continue;
            }
            $this->analyzeParameter($file, $fileContent, $className, $paramName, $paramClass, $searchAfterLine);
        }
    }

    /**
     * Analyze a single constructor parameter
     */
    private function analyzeParameter(
        string $file,
        string $fileContent,
        string $className,
        string $paramName,
        string $paramClass,
        int $constructorLine = 0
    ): void {
        if (in_array($paramClass, self::IGNORED_CLASSES, true) || $this->isArgumentIgnored($paramClass)) {
            return;
        }

        $lineNumber = Content::getLineNumber($fileContent, $paramName, $constructorLine);

        if ($this->handleModelViolation($file, $lineNumber, $paramName, $paramClass, $className, $fileContent)) {
            return;
        }

        if (!Types::isNonMagentoLibrary($paramClass)) {
            $message = sprintf(
                'Specific class "%s" injected in %s. Consider using a factory, builder, or '
                . 'interface instead. (Note: This is a suggestion - manual verification recommended)',
                $paramClass,
                $paramName
            );
            $this->addViolation(
                'genericClass',
                $file,
                $lineNumber,
                $message,
                'medium',
                ['specificClasses' => [$paramClass => $paramName]]
            );
        }
    }

    /**
     * Handle model-specific violations (collection, repository, API interface, resource model)
     * Returns true if a violation was recorded
     */
    private function handleModelViolation(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass,
        string $className,
        string $fileContent = ''
    ): bool {
        $handled = false;
        $shouldCheckModel = false;

        if (Types::isCollectionType($paramClass)) {
            if ($fileContent !== '' && !Classes::extendsClass($fileContent, 'Magento\Framework\Model\AbstractModel')) {
                $this->addCollectionError($file, $lineNumber, $paramName, $paramClass);
            }
            $handled = true;
            $shouldCheckModel = true;
        }

        if (Types::isRepository($paramClass)) {
            $this->addRepositoryError($file, $lineNumber, $paramName, $paramClass);
            $handled = true;
            $shouldCheckModel = true;
        }

        if (Types::hasApiInterface($paramClass)) {
            $interfaceName = Types::getApiInterface($paramClass);
            $message = sprintf(
                'Model "%s" implements an API interface but is injected as concrete class in %s. '
                . 'Inject the API interface instead to respect preferences and coding standards.',
                $paramClass,
                $paramName
            );
            $this->addViolation(
                'modelWithInterface',
                $file,
                $lineNumber,
                $message,
                'high',
                ['models' => [$paramClass => ['interface' => $interfaceName]]]
            );
            $handled = true;
        }

        if (!$shouldCheckModel && Types::isResourceModel($paramClass)) {
            if ($this->shouldFlagResourceModel($paramClass, $className)) {
                $message = sprintf(
                    'Resource Model "%s" injected in %s. Resource models should not be directly '
                    . 'injected. Use a repository instead for better separation of concerns. '
                    . '(Manual refactoring required - no auto-fix available)',
                    $paramClass,
                    $paramName
                );
                $this->addViolation('resourceModel', $file, $lineNumber, $message, 'medium');
            }
            // Always handled — legitimate resource model injections shouldn't trigger generic rule
            $handled = true;
        }

        if (!$handled && defined('EA_SCAN_PATH')) {
            $classFile = Classes::resolveClassToFile(ltrim($paramClass, '\\'));
            if ($classFile !== null) {
                $classContent = @file_get_contents($classFile);
                if ($classContent !== false && (
                    Classes::extendsClass($classContent, 'Magento\Framework\Model\AbstractModel')
                    || Classes::extendsClass($classContent, 'Magento\Framework\Model\AbstractExtensibleModel')
                )) {
                    $message = sprintf(
                        'Stateful model "%s" injected in %s. This class extends AbstractModel '
                        . 'and holds mutable state. Use "%sFactory" to create fresh instances.',
                        $paramClass,
                        $paramName,
                        $paramClass
                    );
                    $this->addViolation('statefulModel', $file, $lineNumber, $message, 'medium',
                        ['specificClasses' => [$paramClass => $paramName]]);
                    $handled = true;
                }
            }
        }

        return $handled;
    }

    /**
     * Check if resource model should be flagged
     */
    private function shouldFlagResourceModel(string $paramClass, string $className): bool
    {
        return !Types::isResourceModel($className)
            && !Types::isRepository($className);
    }

    /**
     * Check if argument should be ignored
     */
    private function isArgumentIgnored(string $className): bool
    {
        if (
            in_array($className, Classes::BASIC_TYPES, true) ||
            Types::matchesSuffix($className, self::LEGITIMATE_SUFFIXES) ||
            Types::matchesSubstring($className, self::IGNORED_SUBSTRINGS)
        ) {
            return true;
        }

        return ClassToProxy::isRequired($className);
    }

    /**
     * Add error methods - unified approach
     */
    private function addViolation(string $category, string $file, int $line, string $message, string $severity, array $extra = []): void
    {
        $this->resultsByCategory[$category][] = Formater::formatError($file, $line, $message, $severity, 0, $extra);
        $this->foundCount++;
    }

    private function addCollectionError(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
        $message = sprintf(
            'Collection "%s" injected in %s. Collections must use Factory pattern. Inject "%sFactory" instead.',
            $paramClass,
            $paramName,
            $paramClass
        );
        $this->addViolation(
            'collection',
            $file,
            $lineNumber,
            $message,
            'high',
            ['collections' => [$paramClass => $paramName]]
        );
    }

    private function addRepositoryError(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
        if (preg_match('/^(.+)\\\\Model\\\\(.+)Repository$/', $paramClass, $matches)) {
            $interfaceName = $matches[1] . '\\Api\\' . $matches[2] . 'RepositoryInterface';
        } else {
            $interfaceName = $paramClass . 'Interface';
        }

        $message = sprintf(
            'Repository "%s" injected as concrete class in %s. Use interface "%s" instead.',
            $paramClass,
            $paramName,
            $interfaceName
        );
        $this->addViolation(
            'repository',
            $file,
            $lineNumber,
            $message,
            'high',
            ['repositories' => [$paramClass => ['interface' => $interfaceName]]]
        );
    }

    /**
     * Generate report with separate entries for each violation type
     */
    public function getReport(): array
    {
        $report = [];

        foreach (self::RULE_CONFIGS as $category => $config) {
            if (!empty($this->resultsByCategory[$category])) {
                $report[] = [
                    'ruleId' => $config['ruleId'],
                    'name' => $config['name'],
                    'shortDescription' => $config['shortDescription'],
                    'longDescription' => $config['longDescription'],
                    'files' => $this->resultsByCategory[$category],
                ];
            }
        }

        return $report;
    }
}
