<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\ClassToProxy;
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
     * Classes that are always ignored (legitimate specific class injections)
     * These are typically configuration, status, or state classes that don't
     * follow the factory/interface pattern and are meant to be injected directly.
     */
    private const IGNORED_CLASSES = [
        // EAV configuration
        'Magento\Eav\Model\Validator\Attribute\Backend',
        'Magento\Eav\Model\Config',
        'Magento\Eav\Model\Entity\Attribute\Config',
        // CMS
        'Magento\Cms\Model\Page',
        // Theme/UI
        'Magento\Theme\Block\Html\Header\Logo',
        // Catalog - Visibility and Status
        'Magento\Catalog\Model\Product\Visibility',
        'Magento\Catalog\Model\Product\Attribute\Source\Status',
        'Magento\Catalog\Model\Product\Type',
        'Magento\Catalog\Model\Product\Media\Config',
        'Magento\CatalogInventory\Model\Stock',
        // Sales - Order Status and State
        'Magento\Sales\Model\Order\Status',
        'Magento\Sales\Model\Order\Config',
        'Magento\Sales\Model\Order\StatusFactory',
        // Customer
        'Magento\Customer\Model\Group',
        'Magento\Customer\Model\Customer\Attribute\Source\Group',
        // Store
        'Magento\Store\Model\StoreManager',
        'Magento\Store\Model\Store',
        // Indexer
        'Magento\Indexer\Model\Indexer\State',
        // Tax
        'Magento\Tax\Model\Calculation',
        'Magento\Tax\Model\Config',
        // Directory
        'Magento\Directory\Model\Currency',
        'Magento\Directory\Model\Country',
        'Magento\Directory\Model\Region',
        // Quote
        'Magento\Quote\Model\Quote\Address\RateResult\Method',
        'Magento\Quote\Model\Quote\Item\Option',
    ];

    /**
     * Known non-Magento PHP library vendor prefixes.
     * These libraries don't have Magento-style factories and should not
     * be flagged by the generic "use Factory" suggestion.
     */
    private const NON_MAGENTO_VENDORS = [
        'GuzzleHttp\\',
        'Monolog\\',
        'Psr\\',
        'Symfony\\',
        'Laminas\\',
        'League\\',
        'Composer\\',
        'Doctrine\\',
        'phpDocumentor\\',
        'PHPUnit\\',
        'Webmozart\\',
        'Ramsey\\',
        'Firebase\\',
        'Google\\',
        'Aws\\',
        'Carbon\\',
        'Brick\\',
        'Sabberworm\\',
        'Pelago\\',
        'Colinodell\\',
        'Fig\\',
        'Zend\\',
    ];

    /**
     * Results organized by violation type
     */
    private array $collectionResults = [];
    private array $repositoryResults = [];
    private array $modelWithInterfaceResults = [];
    private array $resourceModelResults = [];
    private array $genericClassResults = [];

    /**
     * Results for classes that have children (fixer cannot handle these)
     */
    private array $collectionWithChildrenResults = [];
    private array $repositoryWithChildrenResults = [];

    /**
     * Map of parent class FQCN => list of child class FQCNs
     */
    private array $classChildren = [];

    /**
     * Map of class FQCN => file path (for resolving classes)
     */
    private array $classToFile = [];

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
        return 'In Magento 2, certain classes should never be directly injected in constructors. Collections must use factories, repositories must use interfaces, models with API interfaces should inject the interface, and resource models should be replaced with the repository pattern. This ensures proper abstraction, allows preferences to work correctly, and follows Magento 2 coding standards.';
    }

    /**
     * Process PHP files to detect specific class injection issues
     *
     * @param array $files Array of files grouped by type
     */
    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        // First pass: build class hierarchy to detect parent-child relationships
        $this->buildClassHierarchy($files['php']);

        foreach ($files['php'] as $file) {
            $fileContent = file_get_contents($file);
            if ($fileContent === false) {
                continue;
            }

            // Skip if no constructor
            if (!str_contains($fileContent, '__construct')) {
                continue;
            }

            $this->analyzeFile($file, $fileContent);
        }

        // Output counts for each rule type
        if (!empty($this->collectionResults)) {
            echo "  \033[31m✗\033[0m Collections without factory: \033[1;31m" . count($this->collectionResults) . "\033[0m\n";
        }
        if (!empty($this->collectionWithChildrenResults)) {
            echo "  \033[33m!\033[0m Collections with children (manual fix): \033[1;33m" . count($this->collectionWithChildrenResults) . "\033[0m\n";
        }
        if (!empty($this->repositoryResults)) {
            echo "  \033[31m✗\033[0m Repositories without interface: \033[1;31m" . count($this->repositoryResults) . "\033[0m\n";
        }
        if (!empty($this->repositoryWithChildrenResults)) {
            echo "  \033[33m!\033[0m Repositories with children (manual fix): \033[1;33m" . count($this->repositoryWithChildrenResults) . "\033[0m\n";
        }
        if (!empty($this->modelWithInterfaceResults)) {
            echo "  \033[31m✗\033[0m Models should use API interface: \033[1;31m" . count($this->modelWithInterfaceResults) . "\033[0m\n";
        }
        if (!empty($this->resourceModelResults)) {
            echo "  \033[33m!\033[0m Resource models injected: \033[1;33m" . count($this->resourceModelResults) . "\033[0m\n";
        }
        if (!empty($this->genericClassResults)) {
            echo "  \033[33m!\033[0m Generic specific class injections: \033[1;33m" . count($this->genericClassResults) . "\033[0m\n";
        }
    }

    /**
     * Build class hierarchy map from all PHP files
     * Creates a map of parent class => [child classes]
     *
     * @param array $phpFiles List of PHP file paths
     */
    private function buildClassHierarchy(array $phpFiles): void
    {
        $parentToChildren = [];
        $classToFile = [];

        foreach ($phpFiles as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Extract namespace
            if (!preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
                continue;
            }
            $namespace = trim($nsMatch[1]);

            // Match: class ClassName extends ParentClass
            if (!preg_match('/class\s+(\w+)\s+extends\s+([^\s{]+)/', $content, $classMatch)) {
                continue;
            }

            $className = $namespace . '\\' . $classMatch[1];
            $parentShortName = trim($classMatch[2]);

            // Store class to file mapping
            $classToFile[$className] = $file;

            // Resolve parent class to FQCN
            $parentFqcn = $this->resolveClassNameFromContent($parentShortName, $content, $namespace);

            if (!isset($parentToChildren[$parentFqcn])) {
                $parentToChildren[$parentFqcn] = [];
            }
            $parentToChildren[$parentFqcn][] = $className;
        }

        $this->classChildren = $parentToChildren;
        $this->classToFile = $classToFile;
    }

    /**
     * Resolve a short class name to its fully qualified class name
     *
     * @param string $shortName The short class name (e.g., "Collection" or "AbstractCollection")
     * @param string $fileContent The file content to search for use statements
     * @param string $namespace The current namespace
     * @return string The fully qualified class name
     */
    private function resolveClassNameFromContent(string $shortName, string $fileContent, string $namespace): string
    {
        // If already fully qualified
        if (str_contains($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        // Check use statements
        $pattern = '/use\s+([^;]+\\\\' . preg_quote($shortName, '/') . ')\s*;/';
        if (preg_match($pattern, $fileContent, $useMatch)) {
            return trim($useMatch[1]);
        }

        // Check use statements with alias
        $pattern = '/use\s+([^;]+)\s+as\s+' . preg_quote($shortName, '/') . '\s*;/';
        if (preg_match($pattern, $fileContent, $useMatch)) {
            return trim($useMatch[1]);
        }

        // Assume same namespace
        return $namespace . '\\' . $shortName;
    }

    /**
     * Check if a class has children (classes that extend it) in the scanned codebase
     *
     * @param string $className Fully qualified class name
     * @return bool True if the class has children
     */
    private function hasChildren(string $className): bool
    {
        return !empty($this->classChildren[$className]);
    }

    /**
     * Get the list of children for a class
     *
     * @param string $className Fully qualified class name
     * @return array List of child class names
     */
    private function getChildren(string $className): array
    {
        return $this->classChildren[$className] ?? [];
    }

    /**
     * Analyze a single file for specific class injection issues
     *
     * @param string $file File path
     * @param string $fileContent File contents
     */
    private function analyzeFile(string $file, string $fileContent): void
    {
        // Skip Factory classes - they're designed to instantiate concrete classes
        if ($this->isFactory($fileContent) || $this->isCommand($fileContent)) {
            return;
        }

        // Parse constructor parameters
        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        if (empty($constructorParameters)) {
            return;
        }

        // Parse imported classes
        $importedClasses = Classes::parseImportedClasses($fileContent);

        // Consolidate to get full class names
        $consolidatedParameters = Classes::consolidateParameters($constructorParameters, $importedClasses);

        if (empty($consolidatedParameters)) {
            return;
        }

        // Extract the class name from the file for context
        $className = $this->extractClassName($fileContent);

        // Get parameters passed to parent::__construct() - these should not be flagged
        // because we can't change them without breaking the parent class contract
        $parentConstructorParams = $this->getParentConstructorParams($fileContent);

        // Analyze each parameter
        foreach ($consolidatedParameters as $paramName => $paramClass) {
            // Skip if this parameter is passed to parent::__construct()
            if (in_array($paramName, $parentConstructorParams, true)) {
                continue;
            }
            $this->analyzeParameter($file, $fileContent, $className, $paramName, $paramClass);
        }
    }

    /**
     * Extract parameter names that are passed to parent::__construct()
     *
     * @param string $fileContent
     * @return array List of parameter names (without $)
     */
    private function getParentConstructorParams(string $fileContent): array
    {
        $params = [];

        // Match parent::__construct(...) call - can span multiple lines
        if (!preg_match('/parent\s*::\s*__construct\s*\(([^)]*)\)/s', $fileContent, $match)) {
            return $params;
        }

        $argsString = $match[1];

        // Extract all $variableName references from the arguments
        if (preg_match_all('/\$(\w+)/', $argsString, $varMatches)) {
            $params = $varMatches[1];
        }

        return $params;
    }

    /**
     * Extract the class name from file content
     *
     * @param string $fileContent
     * @return string
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
     *
     * @param string $file File path
     * @param string $fileContent File content
     * @param string $className Class being analyzed
     * @param string $paramName Parameter name
     * @param string $paramClass Parameter class
     */
    private function analyzeParameter(
        string $file,
        string $fileContent,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        // Skip ignored classes
        if (in_array($paramClass, self::IGNORED_CLASSES, true)) {
            return;
        }

        // Skip if argument should be ignored based on patterns
        if ($this->isArgumentIgnored($paramClass)) {
            return;
        }

        // Get line number for the parameter
        $lineNumber = Content::getLineNumber($fileContent, $paramName);

        // Check for specific problematic patterns
        if ($this->isModel($paramClass)) {
            // Collection must use Factory
            if ($this->isCollection($paramClass)) {
                $this->addCollectionError($file, $lineNumber, $paramName, $paramClass);
                return;
            }

            // Repository must use Interface
            if ($this->isRepository($paramClass)) {
                $this->addRepositoryError($file, $lineNumber, $paramName, $paramClass);
                return;
            }

            // Model with API interface should inject interface
            if ($this->hasApiInterface($paramClass)) {
                $this->addModelWithInterfaceError($file, $lineNumber, $paramName, $paramClass);
                return;
            }

            // Resource model should use repository
            // Exception 1: Resource models can legitimately use other resource models internally
            // Exception 2: Repositories must inject their resource model (that's how the pattern works)
            if ($this->isResourceModel($paramClass)) {
                // Skip if injected class is also a repository (rare but valid)
                if ($this->isRepository($paramClass)) {
                    return;
                }
                // Skip if current class is a resource model (internal usage is fine)
                if ($this->isResourceModel($className)) {
                    return;
                }
                // Skip if current class is a Repository - repositories MUST inject resource models
                if ($this->isRepository($className)) {
                    return;
                }
                // Otherwise flag as warning (no auto-fix available)
                $this->addResourceModelError($file, $lineNumber, $paramName, $paramClass);
                return;
            }
        }

        // Generic specific class injection (suggestion only)
        // Skip non-Magento PHP libraries - they don't have Magento-style factories
        if (!$this->isNonMagentoLibrary($paramClass)) {
            $this->addGenericClassWarning($file, $lineNumber, $paramName, $paramClass);
        }
    }

    /**
     * Check if argument should be ignored
     *
     * @param string $className
     * @return bool
     */
    private function isArgumentIgnored(string $className): bool
    {
        return $this->isBasicType($className)
            || $this->isLegitimate($className)
            || $this->isMagentoFramework($className)
            || $this->isContext($className)
            || $this->isSession($className)
            || $this->isHelper($className)
            || $this->isStdLib($className)
            || $this->isSerializer($className)
            || $this->isGenerator($className)
            || ClassToProxy::isRequired($className);
    }

    /**
     * Pattern matching methods
     */

    /**
     * Checks for basic type
     *
     * @param string $className
     * @return bool
     */
    private function isBasicType(string $className): bool
    {
        return in_array($className, ['string', 'int', 'float', 'bool', 'array', 'mixed'], true);
    }

    /**
     * Is argument an Interface or a Factory
     *
     * @param string $className
     * @return bool
     */
    private function isLegitimate(string $className): bool
    {
        return str_ends_with($className, 'Interface') || str_ends_with($className, 'Factory') || str_ends_with($className, 'Provider') || str_ends_with($className, 'Resolver');
    }

    /**
     * Check if the file defines a Factory class
     */
    private function isFactory(string $fileContent): bool
    {
        return preg_match('/class\s+\w*Factory\b/', $fileContent) === 1;
    }

    /**
     * Check if the class extends Symfony\Component\Console\Command\Command.
     * In that case, proxy is preferred for heavy dependencies since commands
     * are instantiated on every CLI invocation.
     *
     * @param string $fileContent The file content to analyze
     * @return bool True if the class is a CLI command
     */
    private function isCommand(string $fileContent): bool
    {
        // Check for Symfony Command import
        if (str_contains($fileContent, 'Symfony\Component\Console\Command\Command')) {
            // Verify it's actually extended (not just used as a type hint)
            if (preg_match('/class\s+\w+\s+extends\s+(?:\\\\?Symfony\\\\Component\\\\Console\\\\Command\\\\)?Command\b/', $fileContent)) {
                return true;
            }
        }

        return false;
    }

    private function isModel(string $className): bool
    {
        return str_contains($className, 'Model');
    }

    private function isCollection(string $className): bool
    {
        return str_contains($className, 'Collection');
    }

    private function isRepository(string $className): bool
    {
        return str_contains($className, 'Repository');
    }

    private function isResourceModel(string $className): bool
    {
        return str_contains($className, 'ResourceModel');
    }

    /**
     * Magento framework classes should not use factories
     *
     * @param string $className
     * @return bool
     */
    private function isMagentoFramework(string $className): bool
    {
        return str_contains($className, 'Magento\Framework');
    }

    private function isContext(string $className): bool
    {
        return str_contains($className, 'Context');
    }

    private function isSession(string $className): bool
    {
        return str_contains($className, 'Session');
    }

    private function isHelper(string $className): bool
    {
        return str_contains($className, 'Helper');
    }

    private function isStdLib(string $className): bool
    {
        return str_contains($className, 'Stdlib');
    }

    private function isSerializer(string $className): bool
    {
        return str_contains($className, 'Serializer');
    }

    private function isGenerator(string $className): bool
    {
        return str_contains($className, 'Generator');
    }

    /**
     * Check if a class is from a known non-Magento PHP library.
     * These libraries don't have Magento-style factories and should not
     * be flagged by the generic "use Factory" suggestion.
     *
     * @param string $className The fully qualified class name
     * @return bool True if the class is from a known non-Magento library
     */
    private function isNonMagentoLibrary(string $className): bool
    {
        foreach (self::NON_MAGENTO_VENDORS as $vendor) {
            if (str_starts_with($className, $vendor)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a class implements an API interface
     *
     * @param string $className
     * @return bool
     */
    private function hasApiInterface(string $className): bool
    {
        // Skip if class doesn't exist (might be in vendor or not autoloadable)
        if (!class_exists($className) && !interface_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
            $interfaces = $reflection->getInterfaceNames();

            foreach ($interfaces as $interface) {
                if (str_contains($interface, 'Api')) {
                    return true;
                }
            }
        } catch (ReflectionException $e) {
            // Class doesn't exist or can't be reflected
            return false;
        }

        return false;
    }

    /**
     * Add error methods
     */

    private function addCollectionError(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass
    ): void {
        $hasChildClasses = $this->hasChildren($paramClass);
        $children = $this->getChildren($paramClass);

        if ($hasChildClasses) {
            $message = sprintf(
                'Collection "%s" injected in %s. Collections must use Factory pattern. However, this class has %d child class(es) in the codebase: %s. Manual refactoring required.',
                $paramClass,
                $paramName,
                count($children),
                implode(', ', array_map(fn($c) => basename(str_replace('\\', '/', $c)), $children))
            );

            $this->collectionWithChildrenResults[] = Formater::formatError(
                $file,
                $lineNumber,
                $message,
                'warning', // Warning since auto-fix won't work
                0,
                ['collections' => [$paramClass => $paramName], 'children' => $children]
            );
        } else {
            $message = sprintf(
                'Collection "%s" injected in %s. Collections must use Factory pattern. Inject "%sFactory" instead.',
                $paramClass,
                $paramName,
                $paramClass
            );

            $this->collectionResults[] = Formater::formatError(
                $file,
                $lineNumber,
                $message,
                'error',
                0,
                ['collections' => [$paramClass => $paramName]]
            );
        }
        $this->foundCount++;
    }

    private function addRepositoryError(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass
    ): void {
        $interfaceName = $this->guessInterfaceName($paramClass);
        $hasChildClasses = $this->hasChildren($paramClass);
        $children = $this->getChildren($paramClass);

        if ($hasChildClasses) {
            $message = sprintf(
                'Repository "%s" injected as concrete class in %s. Use interface "%s" instead. However, this class has %d child class(es) in the codebase: %s. Manual refactoring required.',
                $paramClass,
                $paramName,
                $interfaceName,
                count($children),
                implode(', ', array_map(fn($c) => basename(str_replace('\\', '/', $c)), $children))
            );

            $this->repositoryWithChildrenResults[] = Formater::formatError(
                $file,
                $lineNumber,
                $message,
                'warning', // Warning since auto-fix won't work
                0,
                ['repositories' => [$paramClass => ['interface' => $interfaceName]], 'children' => $children]
            );
        } else {
            $message = sprintf(
                'Repository "%s" injected as concrete class in %s. Use interface "%s" instead.',
                $paramClass,
                $paramName,
                $interfaceName
            );

            $this->repositoryResults[] = Formater::formatError(
                $file,
                $lineNumber,
                $message,
                'error',
                0,
                ['repositories' => [$paramClass => ['interface' => $interfaceName]]]
            );
        }
        $this->foundCount++;
    }

    private function addModelWithInterfaceError(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass
    ): void {
        $interfaceName = $this->getApiInterface($paramClass);
        $message = sprintf(
            'Model "%s" implements an API interface but is injected as concrete class in %s. Inject the API interface instead to respect preferences and coding standards.',
            $paramClass,
            $paramName
        );

        $this->modelWithInterfaceResults[] = Formater::formatError(
            $file,
            $lineNumber,
            $message,
            'error',
            0,
            ['models' => [$paramClass => ['interface' => $interfaceName]]]
        );
        $this->foundCount++;
    }

    private function addResourceModelError(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Resource Model "%s" injected in %s. Resource models should not be directly injected. Use a repository instead for better separation of concerns. (Manual refactoring required - no auto-fix available)',
            $paramClass,
            $paramName
        );

        // No metadata - auto-fix is disabled for this rule because ResourceModel->Repository
        // transformation requires manual refactoring (method signatures differ)
        $this->resourceModelResults[] = Formater::formatError(
            $file,
            $lineNumber,
            $message,
            'warning'  // Changed to warning since no auto-fix
        );
        $this->foundCount++;
    }

    private function addGenericClassWarning(
        string $file,
        int $lineNumber,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Specific class "%s" injected in %s. Consider using a factory, builder, or interface instead. (Note: This is a suggestion - manual verification recommended)',
            $paramClass,
            $paramName
        );

        $this->genericClassResults[] = Formater::formatError(
            $file,
            $lineNumber,
            $message,
            'warning',
            0,
            ['specificClasses' => [$paramClass => $paramName]]
        );
        $this->foundCount++;
    }

    /**
     * Guess the interface name for a repository
     *
     * @param string $className
     * @return string
     */
    private function guessInterfaceName(string $className): string
    {
        // Try to guess the interface name
        // Typical pattern: Vendor\Module\Model\ProductRepository -> Vendor\Module\Api\ProductRepositoryInterface
        if (preg_match('/^(.+)\\\\Model\\\\(.+)Repository$/', $className, $matches)) {
            return $matches[1] . '\\Api\\' . $matches[2] . 'RepositoryInterface';
        }

        // Fallback: just append Interface
        return $className . 'Interface';
    }

    /**
     * Get the API interface implemented by a model class
     *
     * @param string $className
     * @return string
     */
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
            // Fallback
        }
        // Fallback: guess interface name
        // Pattern: Vendor\Module\Model\Entity -> Vendor\Module\Api\Data\EntityInterface
        return str_replace('Model\\', 'Api\\Data\\', $className) . 'Interface';
    }

    /**
     * Guess repository interface for a resource model
     *
     * @param string $resourceClass
     * @return string
     */
    private function guessRepositoryInterface(string $resourceClass): string
    {
        // Pattern: Vendor\Module\Model\ResourceModel\Entity -> Vendor\Module\Api\EntityRepositoryInterface
        if (preg_match('/^(.+)\\\\Model\\\\ResourceModel\\\\(.+)$/', $resourceClass, $matches)) {
            return $matches[1] . '\\Api\\' . $matches[2] . 'RepositoryInterface';
        }
        return $resourceClass . 'RepositoryInterface';
    }

    /**
     * Generate report with separate entries for each violation type
     *
     * @return array
     */
    public function getReport(): array
    {
        $report = [];

        if (!empty($this->collectionResults)) {
            $report[] = [
                'ruleId' => 'collectionMustUseFactory',
                'name' => 'Collection Must Use Factory',
                'shortDescription' => 'Collections must not be injected directly',
                'longDescription' => 'A collection must not be injected in constructor as a specific class. When a collection is needed, a factory of it must be injected and used. This prevents the collection from being instantiated at class construction time, improving performance and preventing issues with collection state.',
                'files' => $this->collectionResults,
            ];
        }

        if (!empty($this->collectionWithChildrenResults)) {
            $report[] = [
                'ruleId' => 'collectionWithChildrenMustUseFactory',
                'name' => 'Collection With Children Must Use Factory',
                'shortDescription' => 'Collections with child classes must not be injected directly (manual fix required)',
                'longDescription' => 'A collection must not be injected in constructor as a specific class. This collection has child classes in the codebase, which means auto-fix cannot be applied safely. Manual refactoring is required to ensure all child classes are also updated appropriately.',
                'files' => $this->collectionWithChildrenResults,
            ];
        }

        if (!empty($this->repositoryResults)) {
            $report[] = [
                'ruleId' => 'repositoryMustUseInterface',
                'name' => 'Repository Must Use Interface',
                'shortDescription' => 'Repositories must use interface injection',
                'longDescription' => 'A repository must not be injected in constructor as a specific class. When a repository is needed, an interface of it must be injected and used. This allows preferences to work correctly and respects Magento 2 coding standards.',
                'files' => $this->repositoryResults,
            ];
        }

        if (!empty($this->repositoryWithChildrenResults)) {
            $report[] = [
                'ruleId' => 'repositoryWithChildrenMustUseInterface',
                'name' => 'Repository With Children Must Use Interface',
                'shortDescription' => 'Repositories with child classes must use interface injection (manual fix required)',
                'longDescription' => 'A repository must not be injected in constructor as a specific class. This repository has child classes in the codebase, which means auto-fix cannot be applied safely. Manual refactoring is required to ensure all child classes are also updated appropriately.',
                'files' => $this->repositoryWithChildrenResults,
            ];
        }

        if (!empty($this->modelWithInterfaceResults)) {
            $report[] = [
                'ruleId' => 'modelUseApiInterface',
                'name' => 'Model Should Use API Interface',
                'shortDescription' => 'Models with API interfaces should inject the interface',
                'longDescription' => 'When a model implements an API interface, the interface should be injected instead of the concrete class. This prevents preferences from being ignored and respects the coding standards. It ensures proper abstraction and allows for easier testing and customization.',
                'files' => $this->modelWithInterfaceResults,
            ];
        }

        if (!empty($this->resourceModelResults)) {
            $report[] = [
                'ruleId' => 'noResourceModelInjection',
                'name' => 'Resource Model Should Not Be Injected',
                'shortDescription' => 'Resource models should use repository pattern',
                'longDescription' => 'A resource model must not be injected in constructor. When data access is needed, a repository should be used instead. This assures better separation of concerns, better code quality, and improves code maintainability. Resource models represent the database layer and should be abstracted behind repositories.',
                'files' => $this->resourceModelResults,
            ];
        }

        if (!empty($this->genericClassResults)) {
            $report[] = [
                'ruleId' => 'specificClassInjection',
                'name' => 'Specific Class Injection',
                'shortDescription' => 'Consider using factory, builder, or interface',
                'longDescription' => 'A class should not be injected in constructor as a specific class. In most cases, a factory, a builder, or an interface should be used. This automatic scan cannot be 100% accurate, please verify manually. Using proper abstraction improves testability, flexibility, and follows dependency inversion principle.',
                'files' => $this->genericClassResults,
            ];
        }

        return $report;
    }
}
