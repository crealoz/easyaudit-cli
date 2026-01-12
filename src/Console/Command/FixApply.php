<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Paths;

final class FixApply implements \EasyAudit\Console\CommandInterface
{
    /**
     * Rules that require di.xml modification (proxy configuration)
     */
    private const PROXY_RULES = [
        'noProxyUsedInCommands',
        'noProxyUsedForHeavyClasses',
    ];

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

        $api = new \EasyAudit\Service\Api();

        $errors = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Failed to parse JSON report: " . json_last_error_msg() . "\n");
            return 65;
        }

        $fixables = $api->getAllowedType();

        // Group findings by file (regular fixes) and by di.xml (proxy fixes)
        $byFile = $this->groupByFile($errors, $fixables);
        $byDiFile = $this->groupByDiFile($errors, $fixables);

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
        // Proxy fixes: cost per type (class) in each di.xml
        foreach ($byDiFile as $types) {
            $cost += count($types); // 1 credit per type
        }

        // Check remaining credits before requesting PR
        $startingCredits = null;
        try {
            $creditInfo = $api->getRemainingCredits();
            $remainingCredits = $creditInfo['credits'];
            $startingCredits = $remainingCredits;
            echo "Your balance: " . ($remainingCredits >= $cost ? GREEN : YELLOW) . $remainingCredits . RESET . " credits\n";


            $totalFiles = count($byFile) + count($byDiFile);
            $msg = "Apply fixes to $totalFiles file(s)";
            if (!empty($byDiFile)) {
                $msg .= " (" . count($byDiFile) . " di.xml)";
            }
            $msg .= " and consume $cost credits?";

            if (!$confirm && !Confirm::confirm($msg)) {
                fwrite(STDOUT, "Cancelled.\n");
                return 0;
            }

            echo "Requesting patches from EasyAudit API...\n";

            if ($remainingCredits < $cost) {
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
        $diffs = [];
        $processErrors = [];
        $totalFiles = count($byFile) + count($byDiFile);
        $current = 0;
        $creditsRemaining = null;

        // Process regular PHP files
        foreach ($byFile as $filePath => $data) {
            $current++;
            $this->renderProgressBar($current, $totalFiles, basename($filePath), 'processing', $creditsRemaining);

            try {
                $payload = $this->prepareFilePayload($filePath, $data);
                $response = $api->requestFilefix($filePath, $payload['content'], $payload['rules']);

                if (!empty($response['diff'])) {
                    $diffs[$filePath] = $response['diff'];
                }

                if (isset($response['credits_remaining'])) {
                    $creditsRemaining = $response['credits_remaining'];
                }
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                // Log payload when "no changes" error occurs
                if (str_contains($errorMsg, 'No changes were generated')) {
                    $this->logNoChanges($filePath, $payload['rules'], $payload['content']);
                }
                $processErrors[$filePath] = $errorMsg;
                continue;
            }
        }

        // Process di.xml files for proxy configurations
        // Uses same instant-pr endpoint but with proxy rule format
        foreach ($byDiFile as $diFilePath => $proxies) {
            $current++;
            $this->renderProgressBar($current, $totalFiles, basename($diFilePath), 'di.xml', $creditsRemaining);

            try {
                $payload = $this->prepareDiPayload($diFilePath, $proxies);
                // Use same endpoint as PHP files, with proxy-specific rule format
                $response = $api->requestFilefix($diFilePath, $payload['content'], $payload['rules']);

                if (!empty($response['diff'])) {
                    $diffs[$diFilePath] = $response['diff'];
                }

                if (isset($response['credits_remaining'])) {
                    $creditsRemaining = $response['credits_remaining'];
                }
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                if (str_contains($errorMsg, 'No changes were generated')) {
                    $this->logNoChanges($diFilePath, $payload['rules'] ?? [], $payload['content'] ?? '');
                }
                $processErrors[$diFilePath] = $errorMsg;
                continue;
            }
        }

        // Clear the progress bar line and show summary
        echo "\r" . str_repeat(' ', 100) . "\r";
        echo GREEN . "Processed $totalFiles file(s)" . RESET;
        if ($creditsRemaining !== null) {
            echo " | Credits remaining: " . GREEN . $creditsRemaining . RESET;
        }
        echo "\n";

        // Log errors to file if any
        if (!empty($processErrors)) {
            $this->logErrors($processErrors);
            echo YELLOW . count($processErrors) . " file(s) failed. See logs/fix-apply-errors.log for details." . RESET . "\n";
        }

        if (empty($diffs)) {
            echo YELLOW . "No patches were generated." . RESET . "\n";
            return 0;
        }

        // Save each file's diff as a separate patch file
        $savedCount = 0;
        foreach ($diffs as $filePath => $diffContent) {
            if (empty($diffContent)) {
                continue;
            }

            $patchFilename = $this->sanitizeFilename($filePath) . '.patch';
            $patchPath = rtrim($patchOut, '/') . '/' . $patchFilename;

            file_put_contents($patchPath, $diffContent);
            $savedCount++;
        }

        echo GREEN . "Saved $savedCount patch file(s) to $patchOut." . RESET . "\n";

        // Calculate and display real cost from actual credits consumed
        if ($startingCredits !== null && $creditsRemaining !== null) {
            $realCost = $startingCredits - $creditsRemaining;
            echo "Total real cost: " . GREEN . $realCost . RESET . " credits\n";
        } else {
            echo "Estimated cost: $cost credits\n";
        }

        return 0;
    }

    /**
     * Group findings by file path instead of by ruleId.
     * Separates proxy rules (which modify di.xml) from regular file fixes.
     *
     * @param array $findings Report findings (grouped by ruleId)
     * @param array $fixables List of fixable ruleIds
     * @return array Files grouped by path with their issues (excludes proxy rules)
     */
    private function groupByFile(array $findings, array $fixables): array
    {
        $byFile = [];

        foreach ($findings as $finding) {
            $ruleId = $finding['ruleId'] ?? '';
            if (!array_key_exists($ruleId, $fixables)) {
                continue;
            }

            // Skip proxy rules - they are handled separately
            if (in_array($ruleId, self::PROXY_RULES, true)) {
                continue;
            }

            foreach ($finding['files'] ?? [] as $file) {
                $filePath = Paths::getAbsolutePath($file['file']);

                if (!isset($byFile[$filePath])) {
                    $byFile[$filePath] = [
                        'issues' => [],
                    ];
                }

                $byFile[$filePath]['issues'][] = [
                    'ruleId' => $ruleId,
                    'metadata' => $file['metadata'] ?? [],
                ];
            }
        }

        return $byFile;
    }

    /**
     * Group proxy findings by di.xml file, then by type (class).
     * Format: [diFile => [type => [['argument' => x, 'proxy' => y], ...]]]
     *
     * @param array $findings Report findings
     * @param array $fixables List of fixable ruleIds
     * @return array Grouped by diFile -> type -> proxies
     */
    private function groupByDiFile(array $findings, array $fixables): array
    {
        $byDiFile = [];

        foreach ($findings as $finding) {
            $ruleId = $finding['ruleId'] ?? '';

            // Only process proxy rules
            if (!in_array($ruleId, self::PROXY_RULES, true)) {
                continue;
            }

            if (!array_key_exists($ruleId, $fixables)) {
                continue;
            }

            foreach ($finding['files'] ?? [] as $file) {
                $metadata = $file['metadata'] ?? [];
                $diFile = $metadata['diFile'] ?? null;
                $type = $metadata['type'] ?? null;
                $argument = $metadata['argument'] ?? null;
                $proxy = $metadata['proxy'] ?? null;

                if (!$diFile || !$type || !$argument || !$proxy) {
                    continue;
                }

                if (!isset($byDiFile[$diFile])) {
                    $byDiFile[$diFile] = [];
                }

                if (!isset($byDiFile[$diFile][$type])) {
                    $byDiFile[$diFile][$type] = [];
                }

                // Avoid duplicates
                $entry = ['argument' => $argument, 'proxy' => $proxy];
                if (!in_array($entry, $byDiFile[$diFile][$type], true)) {
                    $byDiFile[$diFile][$type][] = $entry;
                }
            }
        }

        return $byDiFile;
    }

    /**
     * Prepare payload for a single file in the API expected format.
     * Transforms issues array to rules object with metadata.
     *
     * @param string $filePath Path to the file
     * @param array $data File data with issues
     * @return array Payload with 'content' and 'rules' keys
     */
    private function prepareFilePayload(string $filePath, array $data): array
    {
        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \RuntimeException("Failed to read file: $filePath");
        }

        // Transform issues array to rules object
        $rules = [];
        foreach ($data['issues'] as $issue) {
            $ruleId = $issue['ruleId'];
            $metadata = $issue['metadata'] ?? [];

            // If same rule appears multiple times, merge metadata
            if (!isset($rules[$ruleId])) {
                $rules[$ruleId] = $metadata;
            } else {
                $rules[$ruleId] = array_merge($rules[$ruleId], $metadata);
            }
        }

        return [
            'content' => $fileContent,
            'rules' => $rules,
        ];
    }

    /**
     * Prepare payload for di.xml proxy fix.
     *
     * @param string $diFilePath Path to di.xml file
     * @param array $proxies Proxies grouped by type: [type => [['argument' => x, 'proxy' => y], ...]]
     * @return array Payload with 'content' and 'proxies' keys
     */
    private function prepareDiPayload(string $diFilePath, array $proxies): array
    {
        $fileContent = @file_get_contents($diFilePath);
        if ($fileContent === false) {
            throw new \RuntimeException("Failed to read di.xml file: $diFilePath");
        }

        return [
            'content' => $fileContent,
            'proxies' => $proxies,
        ];
    }

    /**
     * Sanitize a file path to create a valid patch filename.
     *
     * @param string $filePath Original file path
     * @return string Sanitized filename (without extension)
     */
    private function sanitizeFilename(string $filePath): string
    {
        // Remove leading slashes
        $filename = ltrim($filePath, '/');

        // Replace path separators with underscores
        $filename = str_replace(['/', '\\'], '_', $filename);

        // Remove .php or .xml extension (will add .patch)
        $filename = preg_replace('/\.(php|xml)$/', '', $filename);

        return $filename;
    }

    /**
     * Log errors to a file in the logs directory.
     *
     * @param array $errors Associative array of file => error message
     */
    private function logErrors(array $errors): void
    {
        $logDir = 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/fix-apply-errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $content = "[$timestamp] Fix-apply errors:\n";

        foreach ($errors as $file => $error) {
            $content .= "  File: $file\n  Error: $error\n\n";
        }

        file_put_contents($logFile, $content, FILE_APPEND);
    }

    /**
     * Log when API returns no changes for a file.
     * Saves the request payload for debugging.
     *
     * @param string $filePath Path to the file
     * @param array $rules Rules that were sent to API
     * @param string $fileContent Content that was sent to API
     */
    private function logNoChanges(string $filePath, array $rules, string $fileContent): void
    {
        $logDir = 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/fix-apply-no-changes.log';
        $timestamp = date('Y-m-d H:i:s');

        $content = "[$timestamp] No changes generated\n";
        $content .= "File: $filePath\n";
        $content .= "Rules: " . json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $content .= "Content:\n$fileContent\n";
        $content .= str_repeat('=', 80) . "\n\n";

        file_put_contents($logFile, $content, FILE_APPEND);
    }

    /**
     * Render a progress bar with status.
     *
     * @param int $current Current item number
     * @param int $total Total items
     * @param string $filename Current file being processed
     * @param string $status Status text
     * @param int|null $credits Remaining credits (optional)
     */
    private function renderProgressBar(int $current, int $total, string $filename, string $status, ?int $credits): void
    {
        $barWidth = 30;
        $progress = $current / $total;
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

        $line = "\r[$bar] {$percent}% | $current/$total | $filename | $status";
        if ($credits !== null) {
            $line .= " | {$credits} credits";
        }

        echo $line;
    }
}
