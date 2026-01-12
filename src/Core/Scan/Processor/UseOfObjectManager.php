<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class UseOfObjectManager
 *
 * Detects direct usage of ObjectManager in code.
 * The ObjectManager should not be used directly except in Factory classes.
 * Use dependency injection instead.
 *
 * Detects two patterns:
 * 1. Direct ObjectManager usage (ERROR) - ObjectManager is imported and used
 * 2. Useless import (WARNING) - ObjectManager is imported but never used
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class UseOfObjectManager extends AbstractProcessor
{
    /**
     * Results storage
     */
    private array $objectManagerUsages = [];
    private array $uselessImports = [];

    public function getIdentifier(): string
    {
        return 'replaceObjectManager';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Use of ObjectManager';
    }

    public function getMessage(): string
    {
        return 'Detects direct usage of ObjectManager which violates Magento 2 dependency injection principles.';
    }

    public function getLongDescription(): string
    {
        return 'The ObjectManager should not be used directly in Magento 2 code. Use dependency injection in constructors instead. Direct ObjectManager usage bypasses the DI container, makes testing difficult, and is considered an anti-pattern. The only exception is Factory classes which are designed to use ObjectManager internally.';
    }

    /**
     * Process PHP files to detect ObjectManager usage
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

            $this->analyzeFile($file, $fileContent);
        }

        // Output counts for each rule type
        if (!empty($this->objectManagerUsages)) {
            echo "  \033[31m✗\033[0m ObjectManager usages: \033[1;31m" . count($this->objectManagerUsages) . "\033[0m\n";
        }
        if (!empty($this->uselessImports)) {
            echo "  \033[33m!\033[0m Useless ObjectManager imports: \033[1;33m" . count($this->uselessImports) . "\033[0m\n";
        }
    }

    /**
     * Analyze a single file for ObjectManager usage
     * Follows the exact logic from the original Magento module
     *
     * @param string $file File path
     * @param string $fileContent File contents
     */
    private function analyzeFile(string $file, string $fileContent): void
    {
        // Check if ObjectManager is mentioned in the file
        if (!str_contains($fileContent, 'Magento\Framework\ObjectManagerInterface')
            && !str_contains($fileContent, 'Magento\Framework\App\ObjectManager')) {
            return;
        }

        // Check for import statement
        $hasImport = str_contains($fileContent, 'use Magento\Framework\ObjectManagerInterface')
            || str_contains($fileContent, 'use Magento\Framework\App\ObjectManager');

        // Check for actual usage patterns
        $hasUsage = str_contains($fileContent, '$this->objectManager')
            || str_contains($fileContent, '->create(')
            || str_contains($fileContent, '->get(')
            || str_contains($fileContent, '->getInstance(');

        // If imported but not used, it's a useless import
        if ($hasImport && !$hasUsage) {
            $this->addUselessImport($file, $fileContent);
        }
        // If not a Factory class, it's a direct usage error
        elseif (!$this->isFactory($fileContent)) {
            $this->addObjectManagerUsage($file, $fileContent);
        }
    }

    /**
     * Check if the class is a Factory
     * Factories are allowed to use ObjectManager
     *
     * @param string $fileContent
     * @return bool
     */
    private function isFactory(string $fileContent): bool
    {
        return preg_match('/class\s+\w*Factory\b/', $fileContent) === 1;
    }

    /**
     * Record direct ObjectManager usage (ERROR)
     *
     * @param string $file File path
     * @param string $fileContent File content
     */
    private function addObjectManagerUsage(string $file, string $fileContent): void
    {
        // Try to find the line where ObjectManager is used
        $lineNumber = 1;

        // Look for use statement
        if (str_contains($fileContent, 'use Magento\Framework\ObjectManagerInterface')) {
            $lineNumber = Content::getLineNumber($fileContent, 'use Magento\Framework\ObjectManagerInterface');
        } elseif (str_contains($fileContent, 'use Magento\Framework\App\ObjectManager')) {
            $lineNumber = Content::getLineNumber($fileContent, 'use Magento\Framework\App\ObjectManager');
        }

        // Extract injections from ObjectManager calls
        $injections = $this->extractInjections($fileContent);

        $message = 'Direct use of ObjectManager detected. Use dependency injection instead.';

        $this->objectManagerUsages[] = Formater::formatError(
            $file,
            $lineNumber,
            $message,
            'error',
            0,
            [
                'injections' => $injections,
            ]
        );
        $this->foundCount++;
    }

    /**
     * Extract class names from ObjectManager->get() and ->create() calls
     *
     * @param string $fileContent
     * @return array Map of className => propertyName
     */
    private function extractInjections(string $fileContent): array
    {
        $injections = [];
        $classNames = [];

        // Get existing constructor parameter types to avoid adding duplicates
        $existingTypes = $this->getConstructorParameterTypes($fileContent);

        // Pattern 1: $this->objectManager->get(ClassName::class) or ->create(ClassName::class)
        $pattern1 = '/\$this->_?objectManager->(?:get|create)\s*\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*\)/';

        // Pattern 2: $this->objectManager->get('ClassName') or ->create('ClassName')
        $pattern2 = '/\$this->_?objectManager->(?:get|create)\s*\(\s*[\'"]\\\\?([A-Za-z0-9_\\\\]+)[\'"]\s*\)/';

        // Pattern 3: ObjectManager::getInstance()->get(ClassName::class) - direct chained call
        // Handles: ObjectManager::getInstance(), \ObjectManager::getInstance(), \Magento\Framework\App\ObjectManager::getInstance()
        $pattern3 = '/\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance\s*\(\s*\)\s*->(?:get|create)\s*\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*\)/s';

        // Pattern 4: ObjectManager::getInstance()->get('ClassName') - direct chained call
        // Handles: ObjectManager::getInstance(), \ObjectManager::getInstance(), \Magento\Framework\App\ObjectManager::getInstance()
        $pattern4 = '/\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance\s*\(\s*\)\s*->(?:get|create)\s*\(\s*[\'"]\\\\?([A-Za-z0-9_\\\\]+)[\'"]\s*\)/s';

        if (preg_match_all($pattern1, $fileContent, $matches)) {
            $classNames = array_merge($classNames, $matches[1]);
        }

        if (preg_match_all($pattern2, $fileContent, $matches)) {
            $classNames = array_merge($classNames, $matches[1]);
        }

        if (preg_match_all($pattern3, $fileContent, $matches)) {
            $classNames = array_merge($classNames, $matches[1]);
        }

        if (preg_match_all($pattern4, $fileContent, $matches)) {
            $classNames = array_merge($classNames, $matches[1]);
        }

        // Pattern 5 & 6: ObjectManager::getInstance() assigned to local variable
        $localVars = $this->findObjectManagerVariables($fileContent);

        foreach ($localVars as $varName) {
            $escapedVar = preg_quote($varName, '/');

            // $localVar->get(ClassName::class) - may span multiple lines
            $pattern5 = '/' . $escapedVar . '\s*->(?:get|create)\s*\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*\)/s';
            if (preg_match_all($pattern5, $fileContent, $matches)) {
                $classNames = array_merge($classNames, $matches[1]);
            }

            // $localVar->get('ClassName') - may span multiple lines
            $pattern6 = '/' . $escapedVar . '\s*->(?:get|create)\s*\(\s*[\'"]\\\\?([A-Za-z0-9_\\\\]+)[\'"]\s*\)/s';
            if (preg_match_all($pattern6, $fileContent, $matches)) {
                $classNames = array_merge($classNames, $matches[1]);
            }
        }

        // Deduplicate and build injections map
        foreach (array_unique($classNames) as $className) {
            // Skip if this type already exists in the constructor
            // (ObjectManager is just a BC fallback, not a missing dependency)
            if ($this->typeExistsInConstructor($className, $existingTypes)) {
                continue;
            }

            $propertyName = $this->derivePropertyName($className);
            $injections[$className] = $propertyName;
        }

        return $injections;
    }

    /**
     * Extract parameter types from constructor
     *
     * @param string $fileContent
     * @return array List of type names (short and fully qualified)
     */
    private function getConstructorParameterTypes(string $fileContent): array
    {
        $types = [];

        // Parse use statements to resolve short names to FQN
        $imports = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $fileContent, $importMatches)) {
            foreach ($importMatches[1] as $import) {
                $import = trim($import);
                // Handle aliases: use Foo\Bar as Baz
                if (preg_match('/(.+)\s+as\s+(\w+)$/i', $import, $aliasMatch)) {
                    $imports[$aliasMatch[2]] = $aliasMatch[1];
                } else {
                    $parts = explode('\\', $import);
                    $shortName = end($parts);
                    $imports[$shortName] = $import;
                }
            }
        }

        // Find constructor parameters
        if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $fileContent, $match)) {
            $paramsStr = $match[1];

            // Match each parameter: [?]TypeName $paramName
            if (preg_match_all('/(?:private|protected|public|readonly|\s)*\??(\w+)\s+\$\w+/', $paramsStr, $paramMatches)) {
                foreach ($paramMatches[1] as $typeName) {
                    // Add short name
                    $types[] = $typeName;

                    // Add FQN if available from imports
                    if (isset($imports[$typeName])) {
                        $types[] = $imports[$typeName];
                    }
                }
            }
        }

        return $types;
    }

    /**
     * Check if a type (FQN or short name) already exists in constructor parameters
     *
     * @param string $className Full class name to check
     * @param array $existingTypes List of existing type names
     * @return bool
     */
    private function typeExistsInConstructor(string $className, array $existingTypes): bool
    {
        // Get short name for comparison
        $parts = explode('\\', $className);
        $shortName = end($parts);

        // Check both FQN and short name
        return in_array($className, $existingTypes, true)
            || in_array($shortName, $existingTypes, true);
    }

    /**
     * Find local variables that hold ObjectManager::getInstance()
     *
     * @param string $fileContent
     * @return array List of variable names (e.g., ['$objectManager'])
     */
    private function findObjectManagerVariables(string $fileContent): array
    {
        $varNames = [];
        // Match: $objectManager = ObjectManager::getInstance() or \Magento\...\ObjectManager::getInstance()
        if (preg_match_all('/(\$\w+)\s*=\s*\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance\s*\(\s*\)/', $fileContent, $matches)) {
            $varNames = $matches[1];
        }
        return $varNames;
    }

    /**
     * Derive a property name from a class name
     * e.g., Magento\Catalog\Model\ProductFactory -> productFactory
     *
     * @param string $className
     * @return string
     */
    private function derivePropertyName(string $className): string
    {
        // Get the short class name (last part after \)
        $parts = explode('\\', $className);
        $shortName = end($parts);

        // Convert to camelCase (first letter lowercase)
        return lcfirst($shortName);
    }

    /**
     * Record useless ObjectManager import (WARNING)
     *
     * @param string $file File path
     * @param string $fileContent File content
     */
    private function addUselessImport(string $file, string $fileContent): void
    {
        // Find the line of the import statement
        $lineNumber = 1;

        if (str_contains($fileContent, 'use Magento\Framework\ObjectManagerInterface')) {
            $lineNumber = Content::getLineNumber($fileContent, 'use Magento\Framework\ObjectManagerInterface');
        } elseif (str_contains($fileContent, 'use Magento\Framework\App\ObjectManager')) {
            $lineNumber = Content::getLineNumber($fileContent, 'use Magento\Framework\App\ObjectManager');
        }

        $message = 'ObjectManager imported but not used. Remove the unused import.';

        $this->uselessImports[] = Formater::formatError($file, $lineNumber, $message, 'warning');
        $this->foundCount++;
    }

    /**
     * Generate report with separate entries for errors and warnings
     *
     * @return array
     */
    public function getReport(): array
    {
        $report = [];

        if (!empty($this->objectManagerUsages)) {
            $report[] = [
                'ruleId' => 'replaceObjectManager',
                'name' => 'Use of ObjectManager',
                'shortDescription' => 'ObjectManager should not be used directly',
                'longDescription' => 'The ObjectManager should not be used directly in Magento 2 code. Use dependency injection in constructors instead. Direct ObjectManager usage bypasses the DI container, makes testing difficult, and is considered an anti-pattern. The only exception is Factory classes which are designed to use ObjectManager internally.',
                'files' => $this->objectManagerUsages,
            ];
        }

        if (!empty($this->uselessImports)) {
            $report[] = [
                'ruleId' => 'magento.code.useless-object-manager-import',
                'name' => 'Useless ObjectManager Import',
                'shortDescription' => 'ObjectManager imported but not used',
                'longDescription' => 'The ObjectManager was imported but does not seem to be used in the code. Please remove the unused import to keep the code clean.',
                'files' => $this->uselessImports,
            ];
        }

        return $report;
    }
}
