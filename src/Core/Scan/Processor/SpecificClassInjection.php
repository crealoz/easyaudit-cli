<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
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
     */
    private const IGNORED_CLASSES = [
        'Magento\Framework\Escaper',
        'Magento\Framework\Data\Collection\AbstractDb',
        'Magento\Framework\App\State',
        'Magento\Eav\Model\Validator\Attribute\Backend',
    ];

    /**
     * Results organized by violation type
     */
    private array $collectionResults = [];
    private array $repositoryResults = [];
    private array $modelWithInterfaceResults = [];
    private array $resourceModelResults = [];
    private array $genericClassResults = [];

    public function getIdentifier(): string
    {
        return 'specific_class_injection';
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
    }

    /**
     * Analyze a single file for specific class injection issues
     *
     * @param string $file File path
     * @param string $fileContent File contents
     */
    private function analyzeFile(string $file, string $fileContent): void
    {
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

        // Analyze each parameter
        foreach ($consolidatedParameters as $paramName => $paramClass) {
            $this->analyzeParameter($file, $fileContent, $className, $paramName, $paramClass);
        }
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
                $this->addCollectionError($file, $lineNumber, $className, $paramName, $paramClass);
                return;
            }

            // Repository must use Interface
            if ($this->isRepository($paramClass)) {
                $this->addRepositoryError($file, $lineNumber, $className, $paramName, $paramClass);
                return;
            }

            // Model with API interface should inject interface
            if ($this->hasApiInterface($paramClass)) {
                $this->addModelWithInterfaceError($file, $lineNumber, $className, $paramName, $paramClass);
                return;
            }

            // Resource model should use repository
            if ($this->isResourceModel($paramClass) && !$this->isRepository($paramClass)) {
                $this->addResourceModelError($file, $lineNumber, $className, $paramName, $paramClass);
                return;
            }
        }

        // Generic specific class injection (suggestion only)
        $this->addGenericClassWarning($file, $lineNumber, $className, $paramName, $paramClass);
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
            || $this->isInterfaceOrFactory($className)
            || $this->isMagentoFrameworkModel($className)
            || $this->isContext($className)
            || $this->isRegistry($className)
            || $this->isSession($className)
            || $this->isHelper($className)
            || $this->isStdLib($className)
            || $this->isFileSystem($className)
            || $this->isSerializer($className)
            || $this->isGenerator($className);
    }

    /**
     * Pattern matching methods
     */

    private function isBasicType(string $className): bool
    {
        return in_array($className, ['string', 'int', 'float', 'bool', 'array', 'mixed'], true);
    }

    private function isInterfaceOrFactory(string $className): bool
    {
        return str_ends_with($className, 'Interface') || str_ends_with($className, 'Factory');
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

    private function isMagentoFrameworkModel(string $className): bool
    {
        return str_contains($className, 'Magento\Framework\Model');
    }

    private function isContext(string $className): bool
    {
        return str_contains($className, 'Context');
    }

    private function isRegistry(string $className): bool
    {
        return $className === 'Magento\Framework\Registry';
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

    private function isFileSystem(string $className): bool
    {
        return str_contains($className, 'Magento\Framework\Filesystem');
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
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Collection "%s" injected in %s. Collections must use Factory pattern. Inject "%sFactory" instead.',
            $paramClass,
            $paramName,
            $paramClass
        );

        $this->collectionResults[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
    }

    private function addRepositoryError(
        string $file,
        int $lineNumber,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        $interfaceName = $this->guessInterfaceName($paramClass);
        $message = sprintf(
            'Repository "%s" injected as concrete class in %s. Use interface "%s" instead.',
            $paramClass,
            $paramName,
            $interfaceName
        );

        $this->repositoryResults[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
    }

    private function addModelWithInterfaceError(
        string $file,
        int $lineNumber,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Model "%s" implements an API interface but is injected as concrete class in %s. Inject the API interface instead to respect preferences and coding standards.',
            $paramClass,
            $paramName
        );

        $this->modelWithInterfaceResults[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
    }

    private function addResourceModelError(
        string $file,
        int $lineNumber,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Resource Model "%s" injected in %s. Resource models should not be directly injected. Use a repository instead for better separation of concerns.',
            $paramClass,
            $paramName
        );

        $this->resourceModelResults[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
    }

    private function addGenericClassWarning(
        string $file,
        int $lineNumber,
        string $className,
        string $paramName,
        string $paramClass
    ): void {
        $message = sprintf(
            'Specific class "%s" injected in %s. Consider using a factory, builder, or interface instead. (Note: This is a suggestion - manual verification recommended)',
            $paramClass,
            $paramName
        );

        $this->genericClassResults[] = Formater::formatError($file, $lineNumber, $message, 'warning');
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
     * Generate report with separate entries for each violation type
     *
     * @return array
     */
    public function getReport(): array
    {
        $report = [];

        if (!empty($this->collectionResults)) {
            $report[] = [
                'ruleId' => 'magento.di.collection-must-use-factory',
                'name' => 'Collection Must Use Factory',
                'shortDescription' => 'Collections must not be injected directly',
                'longDescription' => 'A collection must not be injected in constructor as a specific class. When a collection is needed, a factory of it must be injected and used. This prevents the collection from being instantiated at class construction time, improving performance and preventing issues with collection state.',
                'files' => $this->collectionResults,
            ];
        }

        if (!empty($this->repositoryResults)) {
            $report[] = [
                'ruleId' => 'magento.di.repository-must-use-interface',
                'name' => 'Repository Must Use Interface',
                'shortDescription' => 'Repositories must use interface injection',
                'longDescription' => 'A repository must not be injected in constructor as a specific class. When a repository is needed, an interface of it must be injected and used. This allows preferences to work correctly and respects Magento 2 coding standards.',
                'files' => $this->repositoryResults,
            ];
        }

        if (!empty($this->modelWithInterfaceResults)) {
            $report[] = [
                'ruleId' => 'magento.di.model-use-api-interface',
                'name' => 'Model Should Use API Interface',
                'shortDescription' => 'Models with API interfaces should inject the interface',
                'longDescription' => 'When a model implements an API interface, the interface should be injected instead of the concrete class. This prevents preferences from being ignored and respects the coding standards. It ensures proper abstraction and allows for easier testing and customization.',
                'files' => $this->modelWithInterfaceResults,
            ];
        }

        if (!empty($this->resourceModelResults)) {
            $report[] = [
                'ruleId' => 'magento.di.no-resource-model-injection',
                'name' => 'Resource Model Should Not Be Injected',
                'shortDescription' => 'Resource models should use repository pattern',
                'longDescription' => 'A resource model must not be injected in constructor. When data access is needed, a repository should be used instead. This assures better separation of concerns, better code quality, and improves code maintainability. Resource models represent the database layer and should be abstracted behind repositories.',
                'files' => $this->resourceModelResults,
            ];
        }

        if (!empty($this->genericClassResults)) {
            $report[] = [
                'ruleId' => 'magento.di.specific-class-injection',
                'name' => 'Specific Class Injection',
                'shortDescription' => 'Consider using factory, builder, or interface',
                'longDescription' => 'A class should not be injected in constructor as a specific class. In most cases, a factory, a builder, or an interface should be used. This automatic scan cannot be 100% accurate, please verify manually. Using proper abstraction improves testability, flexibility, and follows dependency inversion principle.',
                'files' => $this->genericClassResults,
            ];
        }

        return $report;
    }
}
