<?php

namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Console\Util\Filenames;
use EasyAudit\Service\Api;
use EasyAudit\Service\Logger;
use EasyAudit\Service\PayloadPreparers\DiPreparer;
use EasyAudit\Service\PayloadPreparers\GeneralPreparer;
use EasyAudit\Service\PayloadPreparers\PreparerInterface;

final class FixApply implements \EasyAudit\Console\CommandInterface
{
    private int $currentFile = 0;

    private int $totalFiles = 0;

    private Logger $logger;
    private Api $api;
    private array $diffs;

    private int $creditsRemaining = 0;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->api = new Api();
        $this->diffs = [];
    }

    /**
     * Command to apply fixes from a JSON report file.
     * Uses EasyAudit API to generate patches (one per file).
     * Usage: easyaudit fix-apply [options] <path|>
     * Options:
     *   --confirm           Skip confirmation prompt
     *   --patch-out=DIR     Directory to save patch files (default: patches)
     *   --format=FORMAT     Output format (git, patch). Default: git
     *   <path>              Path to JSON report file. If omitted, reads from stdin
     *   --help              Show this help message
     * @param array $argv
     * @return int
     */
    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);
        $help      = Args::optBool($opts, 'help', false);
        if ($help) {
            fwrite(STDOUT, "Usage: easyaudit fix-apply [options] <path|>\n");
            fwrite(STDOUT, "Options:\n");
            fwrite(STDOUT, "  --confirm           Skip confirmation prompt\n");
            fwrite(STDOUT, "  --patch-out=DIR     Directory to save patch files (default: patches)\n");
            fwrite(STDOUT, "  --format=FORMAT     Output format (git, patch). Default: git\n");
            fwrite(STDOUT, "  <path>              Path to JSON report file. If omitted, reads from stdin\n");
            fwrite(STDOUT, "  --help              Show this help message\n");
            return 0;
        }
        $confirm   = Args::optBool($opts, 'confirm');
        $patchOut  = Args::optStr($opts, 'patch-out', 'patches');
        $format    = Args::optStr($opts, 'format', 'json');

        $source = $rest ?? '';
        if ($source === '' && posix_isatty(STDIN)) {
            fwrite(STDERR, "Error: No report file provided.\n");
            fwrite(STDERR, "Usage: easyaudit fix-apply <path-to-report.json>\n");
            fwrite(STDERR, "   or: cat report.json | easyaudit fix-apply\n");
            return 64;
        }
        $json = $source === '' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if (!$json) {
            fwrite(STDERR, "Invalid or empty report.\n");
            return 65;
        }

        if (!is_dir($patchOut) && !@mkdir($patchOut, 0775, true)) {
            fwrite(STDERR, "Failed to create patch directory: $patchOut\n");
            return 73;
        }

        $logger = new Logger();
        $generalPreparer = new GeneralPreparer();
        $diPreparer = new DiPreparer();

        $errors = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Failed to parse JSON report: " . json_last_error_msg() . "\n");
            return 65;
        }

        $fixables = $this->api->getAllowedType();

        // Group findings by file (regular fixes), di.xml (proxy fixes), and duplicate preferences
        $byFile = $generalPreparer->prepareFiles($errors, $fixables);
        $byDiFile = $diPreparer->prepareFiles($errors, $fixables);

        if (empty($byFile) && empty($byDiFile)) {
            fwrite(STDOUT, "No fixable issues found in the report.\n");
            return 0;
        }

        // Count total cost based on issue types
        $cost = 0;
        foreach ($byFile as $data) {
            foreach ($data['issues'] as $issue) {
                $cost += $fixables[$issue['ruleId']] ?? 1;
            }
        }
        // Proxy fixes: 1 credit per di.xml file
        $cost += count($byDiFile);

        // Check remaining credits before requesting PR
        $startingCredits = null;

        // Total items to process (individual files + di.xml files + duplicate groups)
        $this->totalFiles = count($byFile) + count($byDiFile);
        try {
            $creditInfo = $this->api->getRemainingCredits();
            $startingCredits = $this->creditsRemaining = $creditInfo['credits'];
            echo "Your balance: " . ($this->creditsRemaining >= $cost ? GREEN : YELLOW) . $this->creditsRemaining . RESET . " credits\n";
            $msg = "Apply fixes to $this->totalFiles file(s)";
            if (!empty($byDiFile)) {
                $msg .= " (" . count($byDiFile) . " di.xml)";
            }
            $msg .= " and consume $cost credits?";

            if (!$confirm && !Confirm::confirm($msg)) {
                fwrite(STDOUT, "Cancelled.\n");
                return 0;
            }

            echo "Requesting patches from EasyAudit API...\n";

            if ($this->creditsRemaining < $cost) {
                echo YELLOW . "Warning: Insufficient credits. A partial patch will be generated if possible." . RESET . "\n";
                echo "Purchase more credits at: " . BLUE . "https://shop.crealoz.fr/shop/credits-for-easyaudit-fixer/" . RESET . "\n";
                if (!$confirm && !Confirm::confirm("Continue anyway?")) {
                    fwrite(STDOUT, "Cancelled.\n");
                    return 0;
                }
            }
        } catch (\Exception $e) {
            echo YELLOW . "Warning: Could not check credit balance: " . $e->getMessage() . RESET . "\n";
        }

        // Process files one by one to avoid heavy payloads
        $processErrors = [];

        // Process regular PHP files
        $this->preparePayload($generalPreparer, $byFile);

        // Process di.xml files for proxy configurations
        $this->preparePayload($diPreparer, $byDiFile);

        // Clear the progress bar line and show summary
        echo "\r" . str_repeat(' ', 100) . "\r";
        echo GREEN . "Processed $this->totalFiles file(s)" . RESET;
        echo " | Credits remaining: " . GREEN . $this->creditsRemaining . RESET;
        echo "\n";

        // Log errors to file if any
        if (!empty($processErrors)) {
            $logger->logErrors($processErrors);
            echo YELLOW . count($processErrors) . " file(s) failed. See logs/fix-apply-errors.log for details." . RESET . "\n";
        }

        if (empty($this->diffs)) {
            echo YELLOW . "No patches were generated." . RESET . "\n";
            return 0;
        }

        // Save each file's diff as a separate patch file
        $savedCount = 0;
        foreach ($this->diffs as $filePath => $diffContent) {
            if (empty($diffContent)) {
                continue;
            }

            $patchFilename = Filenames::sanitize($filePath) . '.patch';
            $patchPath = rtrim($patchOut, '/') . '/' . $patchFilename;

            file_put_contents($patchPath, $diffContent);
            $savedCount++;
        }

        echo GREEN . "Saved $savedCount patch file(s) to $patchOut." . RESET . "\n";

        // Calculate and display real cost from actual credits consumed
        if ($startingCredits !== null) {
            $realCost = $startingCredits - $this->creditsRemaining;
            echo "Total real cost: " . GREEN . $realCost . RESET . " credits\n";
        } else {
            echo "Estimated cost: $cost credits\n";
        }

        return 0;
    }

    private function preparePayload(PreparerInterface $preparer, $files)
    {
        foreach ($files as $filePath => $data) {
            $this->renderProgressBar(basename($filePath), 'processing');

            try {
                $payload = $preparer->preparePayload($filePath, $data);
                $response = $this->api->requestFilefix($filePath, $payload['content'], $payload['rules']);

                if (!empty($response['diff'])) {
                    $this->diffs[$filePath] = $response['diff'];
                }

                if (isset($response['credits_remaining'])) {
                    $this->creditsRemaining = $response['credits_remaining'];
                }
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                // Log payload when "no changes" error occurs
                if (str_contains($errorMsg, 'No changes were generated')) {
                    $this->logger->logNoChanges($filePath, $payload['rules'], $payload['content']);
                }
                $processErrors[$filePath] = $errorMsg;
                continue;
            }
        }
    }

    /**
     * Render a progress bar with status.
     *
     * @param string $filename Current file being processed
     * @param string $status Status text
     */
    private function renderProgressBar(string $filename, string $status): void
    {
        $barWidth = 30;
        $progress = $this->currentFile / $this->totalFiles;
        $filled = (int) round($barWidth * $progress);
        $empty = $barWidth - $filled;

        $bar = GREEN . str_repeat('█', $filled) . RESET . str_repeat('░', $empty);
        $percent = str_pad((int) ($progress * 100), 3, ' ', STR_PAD_LEFT);

        // Truncate filename if too long
        $maxFilenameLen = 25;
        if (strlen($filename) > $maxFilenameLen) {
            $filename = '...' . substr($filename, -($maxFilenameLen - 3));
        }
        $filename = str_pad($filename, $maxFilenameLen);

        $line = "\r[$bar] {$percent}% | $this->currentFile/$this->totalFiles | $filename | $status";
        if ($this->creditsRemaining !== null) {
            $line .= " | {$this->creditsRemaining} credits";
        }
        $this->currentFile++;

        echo $line;
    }
}
