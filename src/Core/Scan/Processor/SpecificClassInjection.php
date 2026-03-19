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
            'longDescription' => 'A collection must not be injected in constructor as a '
                . 'specific class. When a collection is needed, a factory of it must be '
                . 'injected and used. This prevents the collection from being instantiated '
                . 'at class construction time, improving performance and preventing issues '
                . 'with collection state.',
            'label' => 'Collections without factory',
            'severity' => 'high',
        ],
        'repository' => [
            'ruleId' => 'repositoryMustUseInterface',
            'name' => 'Repository Must Use Interface',
            'shortDescription' => 'Repositories must use interface injection',
            'longDescription' => 'A repository must not be injected in constructor as a '
                . 'specific class. When a repository is needed, an interface of it must be '
                . 'injected and used. This allows preferences to work correctly and respects '
                . 'Magento 2 coding standards.',
            'label' => 'Repositories without interface',
            'severity' => 'high',
        ],
        'modelWithInterface' => [
            'ruleId' => 'modelUseApiInterface',
            'name' => 'Model Should Use API Interface',
            'shortDescription' => 'Models with API interfaces should inject the interface',
            'longDescription' => 'When a model implements an API interface, the interface '
                . 'should be injected instead of the concrete class. This prevents '
                . 'preferences from being ignored and respects the coding standards. It '
                . 'ensures proper abstraction and allows for easier testing and customization.',
            'label' => 'Models should use API interface',
            'severity' => 'high',
        ],
        'resourceModel' => [
            'ruleId' => 'noResourceModelInjection',
            'name' => 'Resource Model Should Not Be Injected',
            'shortDescription' => 'Resource models should use repository pattern',
            'longDescription' => 'A resource model must not be injected in constructor. '
                . 'When data access is needed, a repository should be used instead. This '
                . 'assures better separation of concerns, better code quality, and improves '
                . 'code maintainability. Resource models represent the database layer and '
                . 'should be abstracted behind repositories.',
            'label' => 'Resource models injected',
            'severity' => 'medium',
        ],
        'statefulModel' => [
            'ruleId' => 'statefulModelInjection',
            'name' => 'Stateful Model Injected Directly',
            'shortDescription' => 'Model extending AbstractModel is stateful and should use a Factory.',
            'longDescription' => 'This class extends AbstractModel and holds mutable state '
                . '(loaded database data). Injecting it directly means the same instance is '
                . 'shared, which can cause stale data and side effects. Use a Factory to '
                . 'create fresh instances when needed.',
            'label' => 'Stateful models without factory',
            'severity' => 'medium',
        ],
        'genericClass' => [
            'ruleId' => 'specificClassInjection',
            'name' => 'Specific Class Injection',
            'shortDescription' => 'Consider using factory, builder, or interface',
            'longDescription' => 'A class should not be injected in constructor as a specific '
                . 'class. In most cases, a factory, a builder, or an interface should be '
                . 'used. This automatic scan cannot be 100% accurate, please verify manually. '
                . 'Using proper abstraction improves testability, flexibility, and follows '
                . 'dependency inversion principle.',
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
        return 'In Magento 2, certain classes should never be directly injected in constructors. '
            . 'Collections must use factories, repositories must use interfaces, models with API '
            . 'interfaces should inject the interface, and resource models should be replaced '
            . 'with the repository pattern. This ensures proper abstraction, allows preferences '
            . 'to work correctly, and follows Magento 2 coding standards.';
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
