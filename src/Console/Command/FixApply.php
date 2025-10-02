<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Support\Env;
use EasyAudit\Support\Paths;

final class FixApply implements \EasyAudit\Console\CommandInterface
{

    /**
     * Command to apply fixes from a report. It accepts a JSON report file or reads from stdin.
     * Options:
     * --confirm         : skip confirmation prompt
     * --patch-out=DIR   : directory to save patch files (default: .easyaudit/patches)
     * --git-branch=NAME : (optional) create and switch to a git branch before applying patches
     * The command checks for authentication and prompts for confirmation unless --confirm is used.
     * It saves patch files to the specified directory and can optionally create a git branch.
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
            fwrite(STDOUT, "  --git-branch=NAME   (Optional) Create and switch to a git branch before applying patches\n");
            return 0;
        }
        $confirm   = Args::optBool($opts, 'confirm');
        $patchOut  = Args::optStr($opts, 'patch-out', 'patches');
        $gitBranch = Args::optStr($opts, 'git-branch');
        $format    = Args::optStr($opts, 'format', 'json');

        $source = $rest ?? '';
        $json   = $source === '' ? stream_get_contents(STDIN) : @file_get_contents($source);
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
        $cost = 0;
        $payloadFiles = [];
        foreach ($errors as $finding) {
            if (!in_array($finding['ruleId'], $fixables, true)) {
                continue;
            }
            $payloadFiles[$finding['ruleId']] = [];
            foreach ($finding['files'] as $file) {
                $cost++;
                $payloadFiles[$finding['ruleId']][] = $this->addContentToFix($file);
            }
        }

        if (empty($payloadFiles)) {
            fwrite(STDOUT, "No fixable issues found in the report.\n");
            return 0;
        }


        if (!$confirm && !Confirm::confirm("Apply fixes and consume $cost credits?")) {
            fwrite(STDOUT, "Cancelled.\n");
            return 0;
        }

        $patch = $api->requestPR($payloadFiles, $format);
        file_put_contents(rtrim($patchOut, '/').'/' . time() . '.patch', $patch);

        if ($gitBranch) {
            // optional: integrate git branch creation
        }

        fwrite(STDOUT, "Patches saved to $patchOut (demo).\n");
        return 0;
    }

    private function addContentToFix(array $file): array
    {
        $filePath = Paths::getAbsolutePath($file['file']);
        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \RuntimeException("Failed to read file: $filePath");
        }

        return [
            'path'  => $filePath,
            'content'   => $fileContent
        ];

    }
}
