<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Support\Env;

final class FixPlan implements \EasyAudit\Console\CommandInterface
{
    public function getDescription(): string
    {
        return 'Show a plan of fixes for detected issues';
    }

    public function getSynopsis(): string
    {
        return 'fix-plan [options] <report.json>';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit fix-plan [options] <report.json>

Show a plan of fixes and credit costs for detected issues.

Arguments:
  <report.json>            Path to JSON report file

Options:
  --max-credits=<num>      Maximum credits to use (0 = unlimited)
  --output=<file>          Output file path for the plan
  -h, --help               Show this help message

Examples:
  easyaudit fix-plan report/easyaudit-report.json
  easyaudit fix-plan --max-credits=10 report.json
HELP;
    }

    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);

        if (Args::optBool($opts, 'help')) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }

        $maxCredits = (int)Args::optStr($opts, 'max-credits', '0');
        $output     = Args::optStr($opts, 'output', null);
        $reportFile = $rest[0] ?? null;

        if (!$reportFile || !is_file($reportFile)) {
            fwrite(STDERR, "Missing or invalid report file.\n");
            return 66; // EX_NOINPUT
        }

        $token = Env::getAuthHeader();
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
