<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class UseOfRegistry
 *
 * Detects usage of the deprecated Magento\Framework\Registry class.
 *
 * The Registry pattern is considered an anti-pattern in Magento 2 because:
 * - It bypasses dependency injection
 * - Creates hidden dependencies
 * - Makes code harder to test
 * - Can lead to unexpected state mutations
 * - Is officially deprecated by Magento
 *
 * Recommended alternatives:
 * - Constructor injection for explicit dependencies
 * - Data persistors for session-like storage
 * - Explicit service classes for shared state
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class UseOfRegistry extends AbstractProcessor
{
    /**
     * The Registry class that should not be used
     */
    private const REGISTRY_CLASS = 'Magento\Framework\Registry';

    /**
     * Results storage
     */
    private array $registryUsages = [];

    public function getIdentifier(): string
    {
        return 'use_of_registry';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Use of Registry';
    }

    public function getMessage(): string
    {
        return 'Detects usage of the deprecated Magento\Framework\Registry class in constructor dependencies.';
    }

    public function getLongDescription(): string
    {
        return 'The Registry pattern is deprecated in Magento 2 and should not be used. It acts as a global object holder that bypasses dependency injection, creates hidden dependencies, and makes code harder to test. Use constructor injection for explicit dependencies or data persistors for session-like storage instead.';
    }

    /**
     * Process PHP files to detect Registry usage
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
     * Analyze a single file for Registry usage
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

        // Check each parameter for Registry
        foreach ($consolidatedParameters as $paramName => $paramClass) {
            if ($paramClass === self::REGISTRY_CLASS) {
                $this->addRegistryUsage($file, $fileContent, $className, $paramName);
            }
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
     * Record a Registry usage
     *
     * @param string $file File path
     * @param string $fileContent File content
     * @param string $className Class name
     * @param string $paramName Parameter name containing Registry
     */
    private function addRegistryUsage(
        string $file,
        string $fileContent,
        string $className,
        string $paramName
    ): void {
        // Get line number for the parameter
        $lineNumber = Content::getLineNumber($fileContent, $paramName);

        $message = sprintf(
            'Class "%s" uses deprecated Magento\Framework\Registry in constructor parameter "%s". ' .
            'Use dependency injection or data persistors instead.',
            $className,
            $paramName
        );

        $this->registryUsages[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
    }

    /**
     * Generate report
     *
     * @return array
     */
    public function getReport(): array
    {
        if (empty($this->registryUsages)) {
            return [];
        }

        return [[
            'ruleId' => 'magento.code.use-of-registry',
            'name' => 'Use of Registry',
            'shortDescription' => 'Magento\Framework\Registry is deprecated',
            'longDescription' => 'The Registry pattern is deprecated in Magento 2. It bypasses dependency injection, creates hidden dependencies, makes code harder to test, and can lead to unexpected state mutations. Use constructor injection for explicit dependencies or data persistors for session-like storage instead.',
            'files' => $this->registryUsages,
        ]];
    }
}
