<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use PHPUnit\Event\Runtime\PHP;

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
     * ObjectManager class/interface constants
     */
    private const OM_INTERFACE = 'Magento\Framework\ObjectManagerInterface';
    private const OM_CLASS = 'Magento\Framework\App\ObjectManager';

    /**
     * Results storage
     */
    private array $objectManagerUsages = [];
    private array $uselessImports = [];

    private string $fileContent = '';
    private string $file = '';

    /**
     * Properties assigned via ObjectManager::getInstance()
     */
    private array $assignedProperties = [];

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
        return 'The ObjectManager should not be used directly in Magento 2 code. '
            . 'Use dependency injection in constructors instead. Direct ObjectManager usage '
            . 'bypasses the DI container, makes testing difficult, and is considered an anti-pattern. '
            . 'The only exception is Factory classes which are designed to use ObjectManager internally.';
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
            $count = count($this->objectManagerUsages);
            echo "  \033[31m✗\033[0m ObjectManager usages: \033[1;31m" . $count . "\033[0m\n";
        }
        if (!empty($this->uselessImports)) {
            $count = count($this->uselessImports);
            echo "  \033[33m!\033[0m Useless ObjectManager imports: \033[1;33m" . $count . "\033[0m\n";
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
        if (
            $this->isFactory($fileContent) ||
            (!str_contains($fileContent, self::OM_INTERFACE) &&
            !str_contains($fileContent, self::OM_CLASS))
        ) {
            return;
        }

        // Find the import and its line number once
        $lineNumber = 0;
        $hasImport = Classes::hasImportedClasses([self::OM_INTERFACE, self::OM_CLASS], $fileContent);

        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        $imports = Classes::parseImportedClasses($fileContent);

        $paramTypes = Classes::consolidateParameters($constructorParameters, $imports);

        $directUsage = false;
        $omProperty = null;
        $localProperty = false;

        if (preg_match('/(\$\w+)\s*=\s*\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance/', $fileContent)) {
            $localProperty = true;
        }

        // Check for property assignment: $this->property = ObjectManager::getInstance()
        $this->assignedProperties = [];
        if (preg_match_all('/\$this->(\w+)\s*=\s*\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance/', $fileContent, $propMatches)) {
            $this->assignedProperties = $propMatches[1];
        }

        if (empty($paramTypes)) {
            $directUsage = true;
        } else {
            $omParam = array_filter($paramTypes, function ($paramType) {
                return str_contains($paramType, self::OM_INTERFACE) || str_contains($paramType, self::OM_CLASS);
            });
            if (empty($omParam)) {
                $directUsage = true;
            } else {
                $omParam = array_key_first($omParam);
                try {
                    $omProperty = Classes::getInstantiation($constructorParameters, $omParam, $fileContent);
                } catch (\Exception $e) {
                    $directUsage = true;
                }
            }
        }

        $this->fileContent = $fileContent;
        $this->file = $file;

        $hasUsage = $this->trackUsage($directUsage, $omProperty, $localProperty) > 0;

        // If imported but not used, it's a useless import
        if ($hasImport && !$hasUsage) {
            $this->addUselessImport($file, $lineNumber);
        }
    }

    private function trackUsage(bool $directUsage, ?string $property = null, bool $localProperty = false): int
    {
        $usageCount = 0;
        $patterns = [];

        if ($directUsage) {
            $patterns[] = '/ObjectManager::getInstance\s*\(\s*\)\s*->(?:get|create)\s*\(\s*[\'"]?\\\\?([A-Za-z0-9_\\\\]+)(?:::class|[\'"])/';
        }

        if ($localProperty) {
            preg_match_all('/(\$\w+)\s*=\s*\\\\?(?:Magento\\\\Framework\\\\App\\\\)?ObjectManager::getInstance/', $this->fileContent, $varMatches);
            foreach ($varMatches[1] as $varName) {
                $escapedVar = preg_quote($varName, '/');
                $patterns[] = '/' . $escapedVar . '\s*->(?:get|create)\s*\(\s*[\'"]?\\\\?([A-Za-z0-9_\\\\]+)(?:::class|[\'"])/';
            }
        }

        if ($property) {
            $escapedProp = preg_quote($property, '/');
            $patterns[] = '/' . $escapedProp . '\s*->(?:get|create)\s*\(\s*[\'"]?\\\\?([A-Za-z0-9_\\\\]+)(?:::class|[\'"])/';
        }

        // Track properties assigned via getInstance()
        foreach ($this->assignedProperties as $propName) {
            $patterns[] = '/\$this->' . preg_quote($propName, '/') . '\s*->(?:get|create)\s*\(\s*[\'"]?\\\\?([A-Za-z0-9_\\\\]+)(?:::class|[\'"])/';
        }

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $this->fileContent, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $lineNumber = Content::getLineNumber($this->fileContent, $match[0]);
                $className = $match[1];
                $this->addObjectManagerUsage($this->file, $lineNumber, $className);
                $usageCount++;
            }
        }

        return $usageCount;
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
     * @param int $lineNumber Line number of the usage
     * @param string $className Class being fetched via ObjectManager
     */
    private function addObjectManagerUsage(string $file, int $lineNumber, string $className): void
    {
        $propertyName = $this->derivePropertyName($className);
        $message = "Direct use of ObjectManager to get '$className'. Use dependency injection instead.";

        $this->objectManagerUsages[] = Formater::formatError(
            $file,
            $lineNumber,
            $message,
            'error',
            0,
            [
                'injections' => [$className => $propertyName],
            ]
        );
        $this->foundCount++;
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
     * @param int $lineNumber Line number of the import
     */
    private function addUselessImport(string $file, int $lineNumber): void
    {
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
                'longDescription' => 'The ObjectManager should not be used directly in Magento 2 '
                    . 'code. Use dependency injection in constructors instead. Direct ObjectManager '
                    . 'usage bypasses the DI container, makes testing difficult, and is considered '
                    . 'an anti-pattern. The only exception is Factory classes which are designed '
                    . 'to use ObjectManager internally.',
                'files' => $this->objectManagerUsages,
            ];
        }

        if (!empty($this->uselessImports)) {
            $report[] = [
                'ruleId' => 'magento.code.useless-object-manager-import',
                'name' => 'Useless ObjectManager Import',
                'shortDescription' => 'ObjectManager imported but not used',
                'longDescription' => 'The ObjectManager was imported but does not seem to be used in the code. '
                    . 'Please remove the unused import to keep the code clean.',
                'files' => $this->uselessImports,
            ];
        }

        return $report;
    }
}
