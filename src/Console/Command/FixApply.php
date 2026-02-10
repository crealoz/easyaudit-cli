<?php

namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Console\Util\Filenames;
use EasyAudit\Exception\CliException;
use EasyAudit\Exception\Fixer\RuleNotAppliedException;
use EasyAudit\Service\Api;
use EasyAudit\Service\CliWriter;
use EasyAudit\Service\Logger;
use EasyAudit\Service\PayloadPreparers\DiPreparer;
use EasyAudit\Service\PayloadPreparers\GeneralPreparer;
use EasyAudit\Service\PayloadPreparers\PreparerInterface;
use EasyAudit\Service\Paths;
use EasyAudit\Service\ProjectIdentifier;

final class FixApply implements \EasyAudit\Console\CommandInterface
{
    private int $currentFile = 0;
    private int $totalFiles = 0;
    private array $diffs = [];
    private int $creditsRemaining = 0;
    private string $projectId = '';
    private array $processErrors = [];

    public function __construct(
        private Logger $logger,
        private Api $api,
    ) {
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

        if (Args::optBool($opts, 'help', false)) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }

        $options = $this->parseOptions($opts);
        $errors = $this->loadReport($rest, $options['patchOut']);
        $metaData = $errors['metadata'];
        $scanPath = $this->resolveScanPath($options['scanPath'], $metaData);
        unset($errors['metadata']);

        $this->projectId = ProjectIdentifier::resolve($options['projectName'], $scanPath);
        CliWriter::line("Project: " . CliWriter::blue($this->projectId));

        $fixables = $this->api->getAllowedType();
        $selectedRule = $options['fixByRule'] ? $this->handleRuleSelection($errors, $fixables) : null;

        $prepared = $this->prepareFiles($errors, $fixables, $selectedRule);
        if (empty($prepared['byFile']) && empty($prepared['byDiFile'])) {
            throw new CliException("No fixable issues found in the report.");
        }

        $cost = $this->calculateCost($prepared['byFile'], $prepared['byDiFile'], $fixables);
        $this->totalFiles = count($prepared['byFile']) + count($prepared['byDiFile']);

        $startingCredits = $this->checkCreditsAndConfirm($cost, $options['confirm']);
        if ($startingCredits === false) {
            throw new CliException('Aborted by user');
        }

        $this->executeFixing($prepared);
        $this->reportProcessingResults();

        if (empty($this->diffs)) {
            throw new CliException("No patches were generated.");
        }

        $savedCount = $this->savePatches($options['patchOut'], $scanPath, $options['fixByRule'], $selectedRule);
        $this->showSummary($savedCount, $options['patchOut'], $startingCredits, $cost);

        return 0;
    }

    /**
     * Parse command line options into structured array.
     */
    private function parseOptions(array $opts): array
    {
        return [
            'confirm' => Args::optBool($opts, 'confirm'),
            'patchOut' => Paths::expandTilde(Args::optStr($opts, 'patch-out', 'patches')),
            'projectName' => Args::optStr($opts, 'project-name'),
            'scanPath' => Args::optStr($opts, 'scan-path', '.'),
            'fixByRule' => Args::optBool($opts, 'fix-by-rule'),
        ];
    }

    /**
     * Resolve scan path from options or report metadata.
     */
    private function resolveScanPath(string $scanPath, array $metaData): string
    {
        if ($scanPath === '.' && isset($metaData['scan_path'])) {
            return $metaData['scan_path'];
        }
        return $scanPath;
    }

    /**
     * Prepare files for fixing using both preparers.
     */
    private function prepareFiles(array $errors, array $fixables, ?string $selectedRule = null): array
    {
        $generalPreparer = new GeneralPreparer();
        $diPreparer = new DiPreparer();

        return [
            'byFile' => $generalPreparer->prepareFiles($errors, $fixables, $selectedRule),
            'byDiFile' => $diPreparer->prepareFiles($errors, $fixables, $selectedRule),
            'generalPreparer' => $generalPreparer,
            'diPreparer' => $diPreparer,
        ];
    }

    /**
     * Execute the fixing process.
     */
    private function executeFixing(array $prepared): void
    {
        CliWriter::line("Requesting patches from EasyAudit API...");
        $this->processPayloads($prepared['generalPreparer'], $prepared['byFile']);
        $this->processPayloads($prepared['diPreparer'], $prepared['byDiFile']);
    }

    /**
     * Report processing results to CLI.
     */
    private function reportProcessingResults(): void
    {
        CliWriter::clearLine();
        CliWriter::line(CliWriter::green("Processed $this->totalFiles file(s)") . " | Credits remaining: " . CliWriter::green((string)$this->creditsRemaining));

        if (!empty($this->processErrors)) {
            $cnt = count($this->processErrors);
            if ($cnt <= 10) {
                foreach ($this->processErrors as $error) {
                    CliWriter::warning($error);
                }
            }
            $this->logger->logErrors($this->processErrors);
            CliWriter::info("$cnt file(s) failed. Errors were saved in logs/fix-apply-errors.log.");
        }
    }

    /**
     * Load report from stdin or file, parse JSON, create output directory.
     *
     * @throws CliException
     */
    private function loadReport(?string $source, string $patchOut): array
    {
        $json = $this->readReportSource($source);

        if ($json === false || $json === '') {
            throw new CliException("Invalid or empty report.", 65);
        }

        if (!is_dir($patchOut) && !@mkdir($patchOut, 0775, true)) {
            throw new CliException("Failed to create patch directory: $patchOut", 73);
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CliException("Failed to parse JSON report: " . json_last_error_msg(), 65);
        }

        return $data;
    }

    /**
     * Read report content from file or stdin.
     *
     * @throws CliException
     */
    private function readReportSource(?string $source): string|false
    {
        $hasSource = $source !== null && $source !== '';

        if (!$hasSource && stream_isatty(STDIN)) {
            throw new CliException(
                "No report file provided.\nUsage: easyaudit fix-apply <path-to-report.json>\n   or: cat report.json | easyaudit fix-apply",
                64
            );
        }

        return $hasSource ? @file_get_contents($source) : stream_get_contents(STDIN);
    }

    /**
     * Handle --fix-by-rule interactive selection with output messages.
     */
    private function handleRuleSelection(array $errors, array $fixables): ?string
    {
        try {
            $selectedRule = $this->selectRule($errors, $fixables);
        } catch (RuleNotAppliedException $e) {
            CliWriter::line();
            CliWriter::error($e->getMessage());
            $selectedRule = null;
        }

        CliWriter::line();
        CliWriter::warning("Warning: Apply these patches before requesting another rule.");
        CliWriter::line();

        return $selectedRule;
    }

    /**
     * Calculate total credit cost for all fixes.
     */
    private function calculateCost(array $byFile, array $byDiFile, array $fixables): int
    {
        $cost = 0;
        foreach ($byFile as $data) {
            foreach ($data['issues'] as $issue) {
                $cost += $fixables[$issue['ruleId']] ?? 1;
            }
        }
        // Proxy fixes: 1 credit per di.xml file
        $cost += count($byDiFile);

        return $cost;
    }

    /**
     * Check credit balance and confirm with user.
     *
     * @return int|false Starting credits or false if cancelled
     */
    private function checkCreditsAndConfirm(int $cost, bool $confirm): int|false
    {
        $startingCredits = null;

        try {
            $creditInfo = $this->api->getRemainingCredits($this->projectId);
            $startingCredits = $this->creditsRemaining = $creditInfo['credits'];

            if (isset($creditInfo['project_id'])) {
                $this->projectId = $creditInfo['project_id'];
            }

            $color = $this->creditsRemaining >= $cost ? 'green' : 'yellow';
            CliWriter::labelValue("Your balance", $this->creditsRemaining . " credits", $color);

            $msg = "Apply fixes to $this->totalFiles file(s) and consume $cost credits?";
            if (!$confirm && !Confirm::confirm($msg)) {
                return false;
            }

            if ($this->creditsRemaining < $cost) {
                CliWriter::warning("Warning: Insufficient credits. A partial patch will be generated if possible.");
                $url = "https://shop.crealoz.fr/shop/credits-for-easyaudit-fixer/";
                CliWriter::line("Purchase more credits at: " . CliWriter::blue($url));
                if (!$confirm && !Confirm::confirm("Continue anyway?")) {
                    return false;
                }
            }
        } catch (\Exception $e) {
            CliWriter::warning("Warning: Could not check credit balance: " . $e->getMessage());
        }

        return $startingCredits ?? 0;
    }

    /**
     * Save patch files to output directory.
     */
    private function savePatches(string $patchOut, string $scanPath, bool $fixByRule, ?string $selectedRule): int
    {
        $scanPathAbsolute = realpath($scanPath) ?: $scanPath;
        $savedCount = 0;

        foreach ($this->diffs as $filePath => $diffContent) {
            if (empty($diffContent)) {
                continue;
            }

            $relativePath = Filenames::getRelativePath($filePath, $scanPathAbsolute);

            if ($fixByRule && $selectedRule !== null) {
                $targetDir = rtrim($patchOut, '/') . '/' . $selectedRule;
                $patchFilename = Filenames::getSequencedPath($relativePath, $targetDir);
            } else {
                $targetDir = rtrim($patchOut, '/');
                $patchFilename = $relativePath . '.patch';
            }

            $patchPath = $targetDir . '/' . $patchFilename;
            $patchDir = dirname($patchPath);

            if (!is_dir($patchDir) && !@mkdir($patchDir, 0775, true)) {
                fwrite(STDERR, "Failed to create directory: $patchDir\n");
                continue;
            }

            file_put_contents($patchPath, $diffContent);
            $savedCount++;
        }

        return $savedCount;
    }

    /**
     * Display final summary with saved patches and cost.
     */
    private function showSummary(int $savedCount, string $patchOut, ?int $startingCredits, int $cost): void
    {
        CliWriter::success("Saved $savedCount patch file(s) to $patchOut.");

        if ($startingCredits !== null && $startingCredits > 0) {
            $realCost = $startingCredits - $this->creditsRemaining;
            CliWriter::labelValue("Total real cost", $realCost . " credits");
        } else {
            CliWriter::line("Estimated cost: $cost credits");
        }
    }

    /**
     * Process files through API and collect diffs.
     */
    private function processPayloads(PreparerInterface $preparer, array $files): void
    {
        foreach ($files as $filePath => $data) {
            CliWriter::progressBar($this->currentFile, $this->totalFiles, basename($filePath), 'processing', $this->creditsRemaining);
            $this->currentFile++;

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
                if (str_contains($errorMsg, 'No changes were generated')) {
                    $this->logger->logNoChanges($filePath, $payload['rules'], $payload['content']);
                }
                $this->processErrors[$filePath] = $errorMsg;
            }
        }
    }

    /**
     * Display interactive rule selection menu.
     *
     * @param  array $errors   Parsed report data (array of findings)
     * @param  array $fixables Fixable rule types
     * @return string|null Selected rule ID or null if cancelled
     * @throws RuleNotAppliedException
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
            throw new RuleNotAppliedException('No rules were found.');
        }

        // Display menu
        CliWriter::line("\nWhich rule do you want to fix?");
        $index = 1;
        $ruleMap = [];
        foreach ($ruleCounts as $ruleId => $count) {
            $ruleMap[$index] = $ruleId;
            CliWriter::menuItem($index, $ruleId, $count);
            $index++;
        }
        CliWriter::menuItem(0, "Cancel");
        echo "> ";

        // Read user input
        $input = trim(fgets(STDIN));

        if ($input === '0' || $input === '') {
            throw new RuleNotAppliedException('User did not choose any rule.');
        }

        $selection = (int) $input;
        if (!isset($ruleMap[$selection])) {
            throw new RuleNotAppliedException('User selection does not exist.');
        }

        $selectedRule = $ruleMap[$selection];
        CliWriter::line("Selected: " . CliWriter::green($selectedRule));

        return $selectedRule;
    }
}
