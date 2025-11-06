<?php

namespace EasyAudit\Core\Scan\Processor;

/**
 * Class BlockViewModelRatio
 *
 * This processor analyzes the ratio of Block classes to total classes per module.
 * A high ratio of blocks (> 50%) may indicate poor code organization, as ViewModels
 * should be preferred for presentation logic in modern Magento 2 development.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class BlockViewModelRatio extends AbstractProcessor
{
    private const BLOCK_RATIO_THRESHOLD = 0.5;

    private array $moduleRatios = [];

    public function getIdentifier(): string
    {
        return 'blockViewModelRatio';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->moduleRatios)) {
            echo 'Modules with high Block ratio found: ' . count($this->moduleRatios) . PHP_EOL;
            $report[] = [
                'ruleId' => 'blockViewModelRatio',
                'name' => 'Block vs ViewModel Ratio',
                'shortDescription' => 'Module has a high ratio of Block classes compared to ViewModels.',
                'longDescription' => 'A high ratio of Block classes (> 50%) may indicate poor code organization. In modern Magento 2, ViewModels should be preferred for presentation logic as they provide better separation of concerns, testability, and maintainability. Consider refactoring Block logic into ViewModels.',
                'files' => $this->moduleRatios,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Analyzes the ratio of Block classes to total classes per module to identify potential code organization issues.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        $moduleFiles = $this->segregateFilesByModule($files['php']);

        foreach ($moduleFiles as $moduleName => $moduleFilesList) {
            $ratio = $this->calculateBlockRatio($moduleFilesList);

            if ($ratio > self::BLOCK_RATIO_THRESHOLD) {
                $this->foundCount++;
                $blockCount = $this->countBlockFiles($moduleFilesList);
                $totalCount = count($moduleFilesList);

                $this->moduleRatios[] = [
                    'module' => $moduleName,
                    'ratio' => round($ratio, 2),
                    'blockCount' => $blockCount,
                    'totalCount' => $totalCount,
                    'message' => "Module '$moduleName' has $blockCount Block classes out of $totalCount total classes (" .
                                round($ratio * 100, 1) . "%). Consider using ViewModels for presentation logic.",
                    'level' => 'warning'
                ];
            }
        }
    }

    /**
     * Segregate files by module name (Vendor_Module pattern)
     *
     * @param array $files
     * @return array
     */
    private function segregateFilesByModule(array $files): array
    {
        $moduleFiles = [];

        foreach ($files as $file) {
            $moduleName = $this->extractModuleName($file);

            if ($moduleName === null) {
                continue;
            }

            if (!isset($moduleFiles[$moduleName])) {
                $moduleFiles[$moduleName] = [];
            }

            $moduleFiles[$moduleName][] = $file;
        }

        return $moduleFiles;
    }

    /**
     * Extract module name from file path
     * Expected patterns:
     * - /path/to/vendor/module/...
     * - /app/code/Vendor/Module/...
     * - /vendor/vendor/module-name/...
     *
     * @param string $filePath
     * @return string|null
     */
    private function extractModuleName(string $filePath): ?string
    {
        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);

        // Try to find Magento module pattern (Vendor/Module or vendor/module)
        // Pattern 1: app/code/Vendor/Module
        if (preg_match('#/app/code/([A-Z][a-zA-Z0-9]+)/([A-Z][a-zA-Z0-9]+)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        // Pattern 2: vendor/vendor-name/module-name or vendor/vendor-name/magento2-module-name
        if (preg_match('#/vendor/([a-z0-9-]+)/(?:magento2?-)?([a-z0-9-]+)/#i', $filePath, $matches)) {
            // Convert kebab-case to PascalCase for vendor and module
            $vendor = str_replace(' ', '', ucwords(str_replace('-', ' ', $matches[1])));
            $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $matches[2])));
            return $vendor . '_' . $module;
        }

        // Pattern 3: Generic two-level directory structure that looks like modules
        if (preg_match('#/([A-Z][a-zA-Z0-9]*)/([A-Z][a-zA-Z0-9]*)/(?:Block|Model|ViewModel|Controller|Helper)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        return null;
    }

    /**
     * Calculate the ratio of Block files to total files
     *
     * @param array $files
     * @return float
     */
    private function calculateBlockRatio(array $files): float
    {
        $totalFiles = count($files);
        if ($totalFiles === 0) {
            return 0.0;
        }

        $blockFiles = $this->countBlockFiles($files);

        return $blockFiles / $totalFiles;
    }

    /**
     * Count how many files are in Block directory
     *
     * @param array $files
     * @return int
     */
    private function countBlockFiles(array $files): int
    {
        $blockCount = 0;

        foreach ($files as $file) {
            if ($this->isBlockFile($file)) {
                $blockCount++;
            }
        }

        return $blockCount;
    }

    /**
     * Check if file is in Block directory
     *
     * @param string $file
     * @return bool
     */
    private function isBlockFile(string $file): bool
    {
        // Normalize path separators
        $file = str_replace('\\', '/', $file);

        // Check if /Block/ is in the path
        return preg_match('#/Block/[^/]+\.php$#', $file) === 1;
    }

    public function getName(): string
    {
        return 'Block vs ViewModel Ratio';
    }

    public function getLongDescription(): string
    {
        return 'This processor analyzes the ratio of Block classes to total classes (Blocks, ViewModels, Helpers, etc.) ' .
               'per module. A high ratio of Block classes (more than 50% of all classes) may indicate poor code organization ' .
               'and over-reliance on Blocks for presentation logic. In modern Magento 2 development, ViewModels are ' .
               'preferred for presentation logic because they: (1) Provide better separation of concerns between business ' .
               'logic and presentation, (2) Are more testable with unit tests, (3) Don\'t have dependencies on Block ' .
               'lifecycle, (4) Can be reused across multiple templates and blocks. Consider refactoring heavy Block logic ' .
               'into ViewModels to improve code quality and maintainability. This check helps identify modules that may ' .
               'benefit from architectural improvements.';
    }
}
