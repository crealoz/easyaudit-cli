<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class AdvancedBlockVsViewModel
 *
 * This processor analyzes phtml template files to detect anti-patterns:
 * 1. Use of $this instead of $block (compatibility issue)
 * 2. Excessive data retrieval through blocks instead of ViewModels
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class AdvancedBlockVsViewModel extends AbstractProcessor
{
    /**
     * Methods that are allowed/common in phtml files and shouldn't trigger warnings
     */
    private array $allowedMethods = [
        'getJsLayout',
        'getChildHtml',
        'getChildChildHtml',
        'getBlockHtml',
        'escapeHtml',
        'escapeUrl',
        'escapeJs',
        'getUrl',
        'getBaseUrl',
        'getViewFileUrl',
    ];

    /**
     * Patterns that indicate ViewModel usage (should not trigger warnings)
     */
    private array $viewModelPatterns = [
        '/\$viewModel/',
        '/\$block->getViewModel\(\)/',
        '/\$block->getData\([\'"]view_model[\'"]\)/',
    ];

    private array $useOfThisErrors = [];
    private array $dataCrunchWarnings = [];

    public function getIdentifier(): string
    {
        return 'advancedBlockVsVM';
    }

    public function getFileType(): string
    {
        return 'phtml';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->useOfThisErrors)) {
            echo "  \033[31m✗\033[0m Use of \$this instead of \$block: \033[1;31m" . count($this->useOfThisErrors) . "\033[0m\n";
            $report[] = [
                'ruleId' => 'thisToBlock',
                'name' => 'Use of $this instead of $block',
                'shortDescription' => 'Template uses $this instead of $block variable.',
                'longDescription' => 'Using $this in phtml templates is not recommended as it may not be compatible with alternative templating systems. Using $block ensures broader compatibility and supports a more adaptable templating structure. This is especially important when considering future upgrades or alternative rendering engines.',
                'files' => $this->useOfThisErrors,
            ];
        }

        if (!empty($this->dataCrunchWarnings)) {
            echo "  \033[33m!\033[0m Potential data crunch in phtml: \033[1;33m" . count($this->dataCrunchWarnings) . "\033[0m\n";
            $report[] = [
                'ruleId' => 'dataCrunchInPhtml',
                'name' => 'Potential Data Crunch in Template',
                'shortDescription' => 'Template may be retrieving data through blocks instead of ViewModels.',
                'longDescription' => 'Using blocks to retrieve data or configuration is generally discouraged. ViewModels provide a clearer separation of logic and presentation, making code more testable and maintainable. Consider moving data retrieval logic from blocks to ViewModels for a more structured approach.',
                'files' => $this->dataCrunchWarnings,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Analyzes phtml templates for use of $this instead of $block and potential data crunch anti-patterns.';
    }

    public function process(array $files): void
    {
        if (empty($files['phtml'])) {
            return;
        }

        foreach ($files['phtml'] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $this->checkUseOfThis($file, $content);
            $this->checkDataCrunch($file, $content);
        }
    }

    /**
     * Check if the phtml file uses $this instead of $block
     */
    private function checkUseOfThis(string $file, string $content): void
    {
        // Pattern to detect $this-> usage (but not in comments or strings)
        $pattern = '/\$this->(get|is|has|can)\w+\s*\(/';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $this->foundCount++;

            // Get all matching methods
            $methods = array_unique(array_column($matches[0], 0));
            $methodsList = implode(', ', array_map(function($m) {
                return trim($m);
            }, $methods));

            $lineNumber = Content::getLineNumber($content, $matches[0][0][0]);

            $this->useOfThisErrors[] = Formater::formatError(
                $file,
                $lineNumber,
                "Template uses \$this instead of \$block. Found methods: $methodsList. This may cause compatibility issues.",
                'error'
            );
        }
    }

    /**
     * Check if the phtml file has excessive data crunch patterns
     */
    private function checkDataCrunch(string $file, string $content): void
    {
        // Check if ViewModel is already being used
        $hasViewModel = false;
        foreach ($this->viewModelPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $hasViewModel = true;
                break;
            }
        }

        // Pattern to detect $block->get* or $block->is* calls
        $pattern = '/\$block->(get|is)(\w+)\s*\(/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            $suspiciousCalls = [];

            foreach ($matches as $match) {
                $fullMatch = $match[0];
                $methodType = $match[1]; // 'get' or 'is'
                $methodName = $match[2];
                $fullMethodName = $methodType . $methodName;

                // Skip allowed methods
                if (in_array($fullMethodName, $this->allowedMethods)) {
                    continue;
                }

                // Skip common Magento block methods
                if (in_array($methodName, ['Child', 'ChildHtml', 'Html'])) {
                    continue;
                }

                $suspiciousCalls[] = $fullMatch;
            }

            // Only report if there are multiple suspicious calls and no ViewModel usage
            if (count($suspiciousCalls) >= 3 && !$hasViewModel) {
                $this->foundCount++;

                $uniqueCalls = array_unique($suspiciousCalls);
                $callsList = implode(', ', array_slice($uniqueCalls, 0, 5));
                if (count($uniqueCalls) > 5) {
                    $callsList .= ', ... (' . (count($uniqueCalls) - 5) . ' more)';
                }

                $lineNumber = 1; // Default to line 1

                $this->dataCrunchWarnings[] = Formater::formatError(
                    $file,
                    $lineNumber,
                    "Template has " . count($uniqueCalls) . " data retrieval calls: $callsList. Consider using a ViewModel for better separation of concerns.",
                    'warning'
                );
            }
        }
    }

    public function getName(): string
    {
        return 'Block vs ViewModel';
    }

    public function getLongDescription(): string
    {
        return 'This processor analyzes phtml template files to identify anti-patterns and encourage best practices. ' .
               'It detects two main issues: (1) Use of $this instead of $block - Using $this in templates is not ' .
               'recommended as it may not be compatible with alternative templating systems beyond Magento\'s default. ' .
               'Using $block ensures broader compatibility. (2) Excessive data retrieval through blocks - When templates ' .
               'make many calls to retrieve data through the block (e.g., $block->getData(), $block->getConfig()), ' .
               'it suggests that business logic is tightly coupled with the presentation layer. ViewModels provide a ' .
               'clearer separation of concerns, making code more testable, maintainable, and easier to understand. ' .
               'They encapsulate data preparation logic and provide a clean interface to templates.';
    }
}
