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
        return 'use_of_object_manager';
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

        $message = 'Direct use of ObjectManager detected. Use dependency injection instead.';

        $this->objectManagerUsages[] = Formater::formatError($file, $lineNumber, $message, 'error');
        $this->foundCount++;
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
                'ruleId' => 'magento.code.use-of-object-manager',
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
