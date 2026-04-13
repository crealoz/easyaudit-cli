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
                'longDescription' => 'Detects modules where Block classes exceed 50% of total PHP '
                    . 'classes.' . "\n"
                    . 'Impact: Data preparation logic has accumulated in a layer coupled to '
                    . 'rendering, making the module hard to unit test and reuse outside templates.' . "\n"
                    . 'Why change: Blocks are tied to layout XML and the rendering lifecycle. The '
                    . 'higher the ratio, the harder the module becomes to test, refactor, and extend.' . "\n"
                    . 'How to fix: Extract data preparation into ViewModel classes. Inject them via '
                    . 'layout XML. Keep Blocks thin.',
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
                    'level' => 'medium'
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
        return 'Flags modules where Block classes exceed 50% of total PHP classes.' . "\n"
            . 'Impact: A high Block ratio signals that data preparation logic has accumulated in a layer '
            . 'not designed for it. Blocks are coupled to layout XML and the rendering lifecycle, making '
            . 'them expensive to instantiate in tests and hard to reuse outside their original template.' . "\n"
            . 'Why change: The higher the ratio, the harder the module becomes to test, refactor, and '
            . 'onboard new developers onto. Business logic buried in Blocks cannot be shared across API '
            . 'endpoints or CLI commands.' . "\n"
            . 'How to fix: Create ViewModel classes for presentation logic and inject them via layout XML. '
            . 'Reserve Blocks for rendering concerns only. Aim for a ratio where ViewModels handle data '
            . 'preparation and Blocks remain thin wrappers.';
    }
}
