<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Modules;

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
            $cnt = count($this->moduleRatios);
            echo "  \033[33m!\033[0m Modules with high Block ratio: \033[1;33m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'blockViewModelRatio',
                'name' => 'Block vs ViewModel Ratio',
                'shortDescription' => 'Module has a high ratio of Block classes vs ViewModels.',
                'longDescription' => 'A high ratio of Block classes (> 50%) may indicate poor '
                    . 'code organization. In modern Magento 2, ViewModels should be preferred '
                    . 'for presentation logic as they provide better separation of concerns, '
                    . 'testability, and maintainability. Consider refactoring Block logic into '
                    . 'ViewModels.',
                'files' => $this->moduleRatios,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Analyzes the ratio of Block classes to total classes per module to identify '
            . 'potential code organization issues.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        $moduleFiles = Modules::groupFilesByModule($files['php']);

        foreach ($moduleFiles as $moduleName => $moduleFilesList) {
            $ratio = $this->calculateBlockRatio($moduleFilesList);

            if ($ratio > self::BLOCK_RATIO_THRESHOLD) {
                $this->foundCount++;
                $blockCount = $this->countBlockFiles($moduleFilesList);
                $totalCount = count($moduleFilesList);

                $percent = round($ratio * 100, 1);
                $this->moduleRatios[] = [
                    'module' => $moduleName,
                    'ratio' => round($ratio, 2),
                    'blockCount' => $blockCount,
                    'totalCount' => $totalCount,
                    'message' => "Module '$moduleName' has $blockCount Block classes out of "
                        . "$totalCount total classes ($percent%). Consider using ViewModels "
                        . "for presentation logic.",
                    'level' => 'warning'
                ];
            }
        }
    }

    /**
     * Calculate the ratio of Block files to total files
     *
     * @param  array $files
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
     * @param  array $files
     * @return int
     */
    private function countBlockFiles(array $files): int
    {
        $blockCount = 0;

        foreach ($files as $file) {
            if (Modules::isBlockFile($file)) {
                $blockCount++;
            }
        }

        return $blockCount;
    }

    public function getName(): string
    {
        return 'Block vs ViewModel Ratio';
    }

    public function getLongDescription(): string
    {
        return 'This processor analyzes the ratio of Block classes to total classes (Blocks, '
            . 'ViewModels, Helpers, etc.) per module. A high ratio of Block classes (more than '
            . '50% of all classes) may indicate poor code organization and over-reliance on '
            . 'Blocks for presentation logic. In modern Magento 2 development, ViewModels are '
            . 'preferred for presentation logic because they: (1) Provide better separation of '
            . 'concerns between business logic and presentation, (2) Are more testable with '
            . 'unit tests, (3) Don\'t have dependencies on Block lifecycle, (4) Can be reused '
            . 'across multiple templates and blocks. Consider refactoring heavy Block logic '
            . 'into ViewModels to improve code quality and maintainability. This check helps '
            . 'identify modules that may benefit from architectural improvements.';
    }
}
