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
use EasyAudit\Support\ProjectIdentifier;

final class FixApply implements \EasyAudit\Console\CommandInterface
{
    private int $currentFile = 0;

    private int $totalFiles = 0;

    private Logger $logger;
    private Api $api;
    private array $diffs;

    private int $creditsRemaining = 0;
    private string $projectId = '';

    public function __construct()
    {
        $this->logger = new Logger();
        $this->api = new Api();
        $this->diffs = [];
    }

    public function getDescription(): string
    {
        return 'Apply fixes for detected issues using EasyAudit API';
    }

    public function getSynopsis(): string
    {
        return 'fix-apply [options] <report.json>';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit fix-apply [options] <report.json>

Apply fixes from a JSON report file using the EasyAudit API.
Generates patch files that can be applied to fix detected issues.

Arguments:
  <report.json>            Path to JSON report file (or pipe from stdin)

Options:
  --confirm                Skip confirmation prompt
  --patch-out=<dir>        Directory to save patch files (default: patches)
  --format=<format>        Output format (git, patch). Default: git
  --project-name=<name>    Explicit project identifier (slug)
  --scan-path=<path>       Path to scan root for auto-detection (default: .)
  --fix-by-rule            Fix one rule at a time (interactive selection)
  -h, --help               Show this help message

Modes:
  Default mode:    All fixes for a file combined into one patch
                   Output: patches/{relative/path/to/File}.patch

  --fix-by-rule:   Interactive rule selection, one patch per fix
                   Output: patches/{ruleId}/{relative/path/to/File}.patch

Examples:
  easyaudit fix-apply report/easyaudit-report.json
  cat report.json | easyaudit fix-apply
  easyaudit fix-apply --confirm --patch-out=./fixes report.json
  easyaudit fix-apply --fix-by-rule report.json
HELP;
    }

    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);
        $help      = Args::optBool($opts, 'help', false);
        if ($help) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }
        $confirm     = Args::optBool($opts, 'confirm');
        $patchOut    = \EasyAudit\Support\Paths::expandTilde(Args::optStr($opts, 'patch-out', 'patches'));
        $format      = Args::optStr($opts, 'format', 'json');
        $projectName = Args::optStr($opts, 'project-name');
        $scanPath    = Args::optStr($opts, 'scan-path', '.');
        $fixByRule   = Args::optBool($opts, 'fix-by-rule');

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

        // Try to get scan path from report metadata if not provided
        if ($scanPath === '.' && isset($errors['metadata']['scan_path'])) {
            $scanPath = $errors['metadata']['scan_path'];
        }

        // Resolve project identifier
        $this->projectId = ProjectIdentifier::resolve($projectName, $scanPath);
        echo "Project: " . BLUE . $this->projectId . RESET . "\n";

        $fixables = $this->api->getAllowedType();

        // If --fix-by-rule, show interactive rule selection
        $selectedRule = null;
        if ($fixByRule) {
            $selectedRule = $this->selectRule($errors, $fixables);
            if ($selectedRule === null) {
                fwrite(STDOUT, "No rule selected. Cancelled.\n");
                return 0;
            }
            echo "\n" . YELLOW . "Warning: Apply these patches before requesting another rule." . RESET . "\n\n";
        }

        // Group findings by file (regular fixes), di.xml (proxy fixes), and duplicate preferences
        $byFile = $generalPreparer->prepareFiles($errors, $fixables, $selectedRule);
        $byDiFile = $diPreparer->prepareFiles($errors, $fixables, $selectedRule);

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
            $creditInfo = $this->api->getRemainingCredits($this->projectId);
            $startingCredits = $this->creditsRemaining = $creditInfo['credits'];
            // Use validated project_id from middleware if available
            if (isset($creditInfo['project_id'])) {
                $this->projectId = $creditInfo['project_id'];
            }
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

        // Resolve scan path to absolute for relative path calculation
        $scanPathAbsolute = realpath($scanPath) ?: $scanPath;

        // Save patches using new path structure
        $savedCount = 0;
        foreach ($this->diffs as $filePath => $diffContent) {
            if (empty($diffContent)) {
                continue;
            }

            // Get relative path (e.g., "app/code/Vendor/Module/Model/MyClass")
            $relativePath = Filenames::getRelativePath($filePath, $scanPathAbsolute);

            if ($fixByRule && $selectedRule !== null) {
                // Per-rule mode: patches/{ruleId}/{relativePath}.patch (with sequencing)
                $targetDir = rtrim($patchOut, '/') . '/' . $selectedRule;
                $patchFilename = Filenames::getSequencedPath($relativePath, $targetDir);
            } else {
                // Default mode: patches/{relativePath}.patch
                $targetDir = rtrim($patchOut, '/');
                $patchFilename = $relativePath . '.patch';
            }

            // Create nested directories if needed
            $patchPath = $targetDir . '/' . $patchFilename;
            $patchDir = dirname($patchPath);
            if (!is_dir($patchDir) && !@mkdir($patchDir, 0775, true)) {
                fwrite(STDERR, "Failed to create directory: $patchDir\n");
                continue;
            }

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
                $response = $this->api->requestFilefix($filePath, $payload['content'], $payload['rules'], $this->projectId);

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

    /**
     * Display interactive rule selection menu.
     *
     * @param array $errors Parsed report data (array of findings)
     * @param array $fixables Fixable rule types
     * @return string|null Selected rule ID or null if cancelled
     */
    private function selectRule(array $errors, array $fixables): ?string
    {
        // Count issues per rule (count files, not findings)
        $ruleCounts = [];
        foreach ($errors as $finding) {
            $ruleId = $finding['ruleId'] ?? null;
            if ($ruleId && isset($fixables[$ruleId])) {
                $fileCount = count($finding['files'] ?? []);
                $ruleCounts[$ruleId] = ($ruleCounts[$ruleId] ?? 0) + $fileCount;
            }
        }

        if (empty($ruleCounts)) {
            return null;
        }

        // Display menu
        echo "\nWhich rule do you want to fix?\n";
        $index = 1;
        $ruleMap = [];
        foreach ($ruleCounts as $ruleId => $count) {
            $ruleMap[$index] = $ruleId;
            echo "[" . BLUE . $index . RESET . "] " . $ruleId . " (" . $count . " issue" . ($count > 1 ? 's' : '') . ")\n";
            $index++;
        }
        echo "[" . BLUE . "0" . RESET . "] Cancel\n";
        echo "> ";

        // Read user input
        $input = trim(fgets(STDIN));

        if ($input === '0' || $input === '') {
            return null;
        }

        $selection = (int) $input;
        if (!isset($ruleMap[$selection])) {
            echo YELLOW . "Invalid selection." . RESET . "\n";
            return null;
        }

        $selectedRule = $ruleMap[$selection];
        echo "Selected: " . GREEN . $selectedRule . RESET . "\n";

        return $selectedRule;
    }
}