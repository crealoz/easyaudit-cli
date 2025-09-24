<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Support\Env;

final class FixPlan implements \EasyAudit\Console\CommandInterface
{
    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);
        $maxCredits = (int)Args::optStr($opts, 'max-credits', '0');
        $output     = Args::optStr($opts, 'output', null);
        $reportFile = $rest[0] ?? null;

        if (!$reportFile || !is_file($reportFile)) {
            fwrite(STDERR, "Missing or invalid report file.\n");
            return 66; // EX_NOINPUT
        }

        $token = Env::getToken();
        if (!$token) {
            fwrite(STDERR, "Missing token. Run `auth:login` or set EASYAUDIT_TOKEN.\n");
            return 64;
        }

        $report = json_decode(file_get_contents($reportFile), true);

        // TODO: call API to plan fixes
        $plan = [
            'credits_required' => 2,
            'credits_available' => 10,
            'max_credits' => $maxCredits,
            'patchable' => count($report['findings'] ?? []),
        ];

        $payload = json_encode($plan, JSON_PRETTY_PRINT) . PHP_EOL;
        if ($output) {
            file_put_contents($output, $payload);
        }
        else {
            echo $payload;
        }

        return 0;
    }
}
