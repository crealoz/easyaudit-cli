<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Env;

final class FixApply implements \EasyAudit\Console\CommandInterface
{

    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);
        $confirm   = Args::optBool($opts, 'confirm', false);
        $patchOut  = Args::optStr($opts, 'patch-out', '.easyaudit/patches');
        $gitBranch = Args::optStr($opts, 'git-branch', null);

        $source = $rest[0] ?? '-';
        $json   = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if (!$json) { fwrite(STDERR, "Invalid or empty report.\n"); return 65; }

        $token = Env::getToken();
        if (!$token) {
            fwrite(STDERR, "Missing token. Run `auth:login` or set EASYAUDIT_TOKEN.\n");
            return 64;
        }

        if (!$confirm && !Confirm::confirm("Apply fixes and consume credits?")) {
            fwrite(STDOUT, "Cancelled.\n");
            return 0;
        }

        if (!is_dir($patchOut) && !@mkdir($patchOut, 0775, true)) {
            fwrite(STDERR, "Failed to create patch directory: $patchOut\n");
            return 73;
        }

        // TODO: real API call. Demo patch:
        $demoPatch = "--- a/file.php\n+++ b/file.php\n@@\n- old\n+ new\n";
        file_put_contents(rtrim($patchOut, '/').'/demo.patch', $demoPatch);

        if ($gitBranch) {
            // optional: integrate git branch creation
        }

        fwrite(STDOUT, "Patches saved to $patchOut (demo).\n");
        return 0;
    }
}
