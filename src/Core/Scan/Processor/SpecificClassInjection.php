<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\ClassToProxy;
use EasyAudit\Service\CliWriter;
use ReflectionClass;
use ReflectionException;

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
            'severity' => 'error',
        ],
        'collectionWithChildren' => [
            'ruleId' => 'collectionWithChildrenMustUseFactory',
            'name' => 'Collection With Children Must Use Factory',
            'shortDescription' => 'Collections with children must not be injected directly',
            'longDescription' => 'A collection must not be injected in constructor as a '
                . 'specific class. This collection has child classes in the codebase, which '
                . 'means auto-fix cannot be applied safely. Manual refactoring is required '
                . 'to ensure all child classes are also updated appropriately.',
            'label' => 'Collections with children (manual fix)',
            'severity' => 'warning',
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
            'severity' => 'error',
        ],
        'repositoryWithChildren' => [
            'ruleId' => 'repositoryWithChildrenMustUseInterface',
            'name' => 'Repository With Children Must Use Interface',
            'shortDescription' => 'Repositories with children must use interface injection',
            'longDescription' => 'A repository must not be injected in constructor as a '
                . 'specific class. This repository has child classes in the codebase, which '
                . 'means auto-fix cannot be applied safely. Manual refactoring is required '
                . 'to ensure all child classes are also updated appropriately.',
            'label' => 'Repositories with children (manual fix)',
            'severity' => 'warning',
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
            'severity' => 'error',
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
            'severity' => 'warning',
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
            'severity' => 'warning',
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
    ];

    /**
     * Known non-Magento PHP library vendor prefixes.
     */
    private const NON_MAGENTO_VENDORS = [
        'GuzzleHttp\\', 'Monolog\\', 'Psr\\', 'Symfony\\', 'Laminas\\',
        'League\\', 'Composer\\', 'Doctrine\\', 'phpDocumentor\\', 'PHPUnit\\',
        'Webmozart\\', 'Ramsey\\', 'Firebase\\', 'Google\\', 'Aws\\',
        'Carbon\\', 'Brick\\', 'Sabberworm\\', 'Pelago\\', 'Colinodell\\',
        'Fig\\', 'Zend\\',
    ];

    /**
     * Substrings that indicate an argument should be ignored
     */
    private const IGNORED_SUBSTRINGS = [
        'Magento\Framework', 'Context', 'Session', 'Helper', 'Stdlib', 'Serializer', 'Generator'
    ];

    /**
     * Suffixes that indicate a legitimate injection pattern
     */
    private const LEGITIMATE_SUFFIXES = ['Interface', 'Factory', 'Provider', 'Resolver'];

    /**
     * Basic PHP types
     */
    private const BASIC_TYPES = ['string', 'int', 'float', 'bool', 'array', 'mixed'];

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

        Classes::buildClassHierarchy($files['php']);

        foreach ($files['php'] as $file) {
            $fileContent = file_get_contents($file);
            if ($fileContent === false || !str_contains($fileContent, '__construct')) {
                continue;
            }
            $this->analyzeFile($file, $fileContent);
        }

        $this->printResults();
    }

    /**
     * Analyze a single file for specific class injection issues
     */
    private function analyzeFile(string $file, string $fileContent): void
    {
        if (preg_match('/class\s+\w*Factory\b/', $fileContent) === 1 || $this->isCommand($fileContent)) {
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

        $className = $this->extractClassName($fileContent);
        $parentConstructorParams = $this->getParentConstructorParams($fileContent);

        foreach ($consolidatedParameters as $paramName => $paramClass) {
            if (in_array($paramName, $parentConstructorParams, true)) {
                continue;
            }
            $this->analyzeParameter($file, $fileContent, $className, $paramName, $paramClass);
        }
    }

    /**
     * Extract parameter names that are passed to parent::__construct()
     */
    private function getParentConstructorParams(string $fileContent): array
    {
        if (!preg_match('/parent\s*::\s*__construct\s*\(([^)]*)\)/s', $fileContent, $match)) {
            return [];
        }

        $params = [];
        if (preg_match_all('/\$(\w+)/', $match[1], $varMatches)) {
            $params = $varMatches[1];
        }

        return $params;
    }

    /**
     * Extract the class name from file content
     */
    private function extractClassName(string $fileContent): string
    {
        if (preg_match('/namespace\s+([^;]+);/', $fileContent, $namespaceMatch)) {
            $namespace = trim($namespaceMatch[1]);
            if (preg_match('/class\s+(\w+)/', $fileContent, $classMatch)) {
                return $namespace . '\\' . $classMatch[1];
            }
        }
        return 'UnknownClass';
    }

    /**
     * Analyze a single constructor parameter
     */
    private function analyzeParameter(
        string $file,
        string $fileContent,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        if (in_array($paramClass, self::IGNORED_CLASSES, true) || $this->isArgumentIgnored($paramClass)) {
            return;
        }

        $lineNumber = Content::getLineNumber($fileContent, $paramName);

        if (str_contains($className, 'Model') && $this->handleModelViolation($file, $lineNumber, $paramName, $paramClass, $className)) {
            return;
        }

        if (!$this->isNonMagentoLibrary($paramClass)) {
            $this->addGenericClassWarning($file, $lineNumber, $paramName, $paramClass);
        }
    }

    /**
     * Handle model-specific violations (collection, repository, API interface, resource model)
     * Returns true if a violation was recorded
     */
    private function handleModelViolation(string $file, int $lineNumber, string $paramName, string $paramClass, string $className): bool
    {
        $handled = false;
        if (str_contains($className, 'Collection')) {
            $this->addCollectionError($file, $lineNumber, $paramName, $paramClass);
            $handled = true;
        }

        if ($this->isRepository($paramClass)) {
            $this->addRepositoryError($file, $lineNumber, $paramName, $paramClass);
            $handled = true;
        }

        if ($this->hasApiInterface($paramClass)) {
            $this->addModelWithInterfaceError($file, $lineNumber, $paramName, $paramClass);
            $handled = true;
        }

        if ($this->isResourceModel($paramClass) && $this->shouldFlagResourceModel($paramClass, $className)) {
            $this->addResourceModelError($file, $lineNumber, $paramName, $paramClass);
            $handled = true;
        }

        return $handled;
    }

    /**
     * Check if resource model should be flagged
     */
    private function shouldFlagResourceModel(string $paramClass, string $className): bool
    {
        return !$this->isRepository($paramClass)
            && !$this->isResourceModel($className)
            && !$this->isRepository($className);
    }

    /**
     * Check if argument should be ignored
     */
    private function isArgumentIgnored(string $className): bool
    {
        if (
            in_array($className, self::BASIC_TYPES, true) ||
            $this->matchesSuffix($className, self::LEGITIMATE_SUFFIXES) ||
            $this->matchesSubstring($className, self::IGNORED_SUBSTRINGS)
        ) {
            return true;
        }

        return ClassToProxy::isRequired($className);
    }

    /**
     * Check if class name ends with any of the given suffixes
     */
    private function matchesSuffix(string $className, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if class name contains any of the given substrings
     */
    private function matchesSubstring(string $className, array $substrings): bool
    {
        foreach ($substrings as $substring) {
            if (str_contains($className, $substring)) {
                return true;
            }
        }
        return false;
    }

    private function isCommand(string $fileContent): bool
    {
        if (!str_contains($fileContent, 'Symfony\Component\Console\Command\Command')) {
            return false;
        }
        $pattern = '/class\s+\w+\s+extends\s+(?:\\\\?Symfony\\\\Component\\\\Console\\\\Command\\\\)?Command\b/';
        return preg_match($pattern, $fileContent) === 1;
    }

    private function isRepository(string $className): bool
    {
        return str_contains($className, 'Repository');
    }

    private function isResourceModel(string $className): bool
    {
        return str_contains($className, 'ResourceModel');
    }

    private function isNonMagentoLibrary(string $className): bool
    {
        foreach (self::NON_MAGENTO_VENDORS as $vendor) {
            if (str_starts_with($className, $vendor)) {
                return true;
            }
        }
        return false;
    }

    private function hasApiInterface(string $className): bool
    {
        if (!class_exists($className) && !interface_exists($className)) {
            return false;
        }

        $reflection = new ReflectionClass($className);
        foreach ($reflection->getInterfaceNames() as $interface) {
            if (str_contains($interface, 'Api')) {
                return true;
            }
        }

        return false;
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

        try {
            $children = Classes::getChildren($paramClass);
            $childNames = implode(', ', array_map(fn($c) => basename(str_replace('\\', '/', $c)), $children));
            $message = sprintf(
                'Collection "%s" injected in %s. Collections must use Factory pattern. '
                    . 'However, this class has %d child class(es): %s. Manual refactoring required.',
                $paramClass,
                $paramName,
                count($children),
                $childNames
            );
            $this->addViolation(
                'collectionWithChildren',
                $file,
                $lineNumber,
                $message,
                'warning',
                ['collections' => [$paramClass => $paramName], 'children' => $children]
            );
        } catch (\Exception $e) {
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
                'error',
                ['collections' => [$paramClass => $paramName]]
            );
        }
    }

    private function addRepositoryError(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
        $interfaceName = $this->guessInterfaceName($paramClass);

        try {
            $children = Classes::getChildren($paramClass);
            $childNames = implode(', ', array_map(fn($c) => basename(str_replace('\\', '/', $c)), $children));
            $message = sprintf(
                'Repository "%s" injected as concrete class in %s. Use interface "%s" instead. '
                    . 'However, this class has %d child class(es): %s. Manual refactoring required.',
                $paramClass,
                $paramName,
                $interfaceName,
                count($children),
                $childNames
            );
            $this->addViolation(
                'repositoryWithChildren',
                $file,
                $lineNumber,
                $message,
                'warning',
                ['repositories' => [$paramClass => ['interface' => $interfaceName]], 'children' => $children]
            );
        } catch (\Exception $e) {
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
                'error',
                ['repositories' => [$paramClass => ['interface' => $interfaceName]]]
            );
        }
    }

    private function addModelWithInterfaceError(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
        $interfaceName = $this->getApiInterface($paramClass);
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
            'error',
            ['models' => [$paramClass => ['interface' => $interfaceName]]]
        );
    }

    private function addResourceModelError(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
        $message = sprintf(
            'Resource Model "%s" injected in %s. Resource models should not be directly '
                . 'injected. Use a repository instead for better separation of concerns. '
                . '(Manual refactoring required - no auto-fix available)',
            $paramClass,
            $paramName
        );
        $this->addViolation('resourceModel', $file, $lineNumber, $message, 'warning');
    }

    private function addGenericClassWarning(string $file, int $lineNumber, string $paramName, string $paramClass): void
    {
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
            'warning',
            ['specificClasses' => [$paramClass => $paramName]]
        );
    }

    private function guessInterfaceName(string $className): string
    {
        if (preg_match('/^(.+)\\\\Model\\\\(.+)Repository$/', $className, $matches)) {
            return $matches[1] . '\\Api\\' . $matches[2] . 'RepositoryInterface';
        }
        return $className . 'Interface';
    }

    private function getApiInterface(string $className): string
    {
        try {
            $reflection = new ReflectionClass($className);
            foreach ($reflection->getInterfaceNames() as $interface) {
                if (str_contains($interface, 'Api')) {
                    return $interface;
                }
            }
        } catch (ReflectionException $e) {
            // Fallback below
        }
        return str_replace('Model\\', 'Api\\Data\\', $className) . 'Interface';
    }

    /**
     * Print results summary
     */
    private function printResults(): void
    {
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
