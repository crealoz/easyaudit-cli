<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Paths;

final class FixApply implements \EasyAudit\Console\CommandInterface
{

    /**
     * Command to apply fixes from a JSON report file.
     * Uses EasyAudit API to generate patches.
     * Usage: easyaudit fix-apply [options] <path|>
     * Options:
     *   --confirm           Skip confirmation prompt
     *   --patch-out=DIR     Directory to save patch files (default: patches)
     *  --format=FORMAT     Output format (git, patch). Default: git
     *  --patch-name=NAME   Filename of the created file
     * <path>              Path to JSON report file. If omitted, reads from stdin
     * --help              Show this help message
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
            fwrite(STDOUT, "  --patch-name=NAME   Filename of the created file\n");
            fwrite(STDOUT, "  <path>              Path to JSON report file. If omitted, reads from stdin\n");
            fwrite(STDOUT, "  --help              Show this help message\n");
            return 0;
        }
        $confirm   = Args::optBool($opts, 'confirm');
        $patchOut  = Args::optStr($opts, 'patch-out', 'patches');
        $format    = Args::optStr($opts, 'format', 'json');
        $patchName = Args::optStr($opts, 'patch-name', time());

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
                echo "Prepared fix for " .BLUE.$file['file'].RESET. " (rule: {$finding['ruleId']})\n";
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

        echo "Requesting patches from EasyAudit API...\n";
        echo "This will consume " .GREEN.$cost.RESET. " credits.\n";

        try {
            $patch = $api->requestPR($payloadFiles, $format);
        } catch (\Exception $e) {
            echo RED . "Error: " . $e->getMessage() . RESET . "\n";
            return 1;
        }
        file_put_contents(rtrim($patchOut, '/').'/' . $patchName . '.patch', $patch);

        fwrite(STDOUT, "Patches saved to $patchOut.\n");
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
