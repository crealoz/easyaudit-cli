<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\CommandInterface;
use EasyAudit\Console\Util\Args;
use EasyAudit\Core\Scan\Scanner;
use EasyAudit\Core\Report\JsonReporter;
use EasyAudit\Core\Report\SarifReporter;

final class Scan implements CommandInterface
{
    public function run(array $argv): int
    {
        // if option is help, show help
        if (Args::optBool(Args::parse($argv)[0], 'help')) {
            fwrite(STDOUT, "Usage: easyaudit scan [options] [path]\n\n");
            fwrite(STDOUT, "Options:\n");
            fwrite(STDOUT, "  --format=<format>       Output format (json, sarif). Default: json\n");
            fwrite(STDOUT, "  --exclude=<patterns>    Comma-separated list of glob patterns to exclude\n");
            fwrite(STDOUT, "  --exclude-ext=<exts>    Comma-separated list of file extensions to exclude (e.g. .log,.tmp)\n");
            fwrite(STDOUT, "  --output=<file>         Output file path. Default: report/easyaudit-report.<format>\n");
            fwrite(STDOUT, "  --help                  Show this help message\n");
            return 0;
        }

        [$opts, $rest] = Args::parse($argv);
        $format   = strtolower(Args::optStr($opts, 'format', 'json')) ?? 'json';
        $exclude  = Args::optStr($opts, 'exclude', '');
        $output   = Args::optStr($opts, 'output');
        $excludedExt = Args::optArr($opts, 'exclude-ext');
        $path    = $rest ?: '.';
        define('EA_SCAN_PATH', $path);

        $scanner  = new Scanner();
        $result   = $scanner->run($exclude, $excludedExt);

        $payload = match ($format) {
            'sarif' => (new SarifReporter())->generate($result),
            'json' => (new JsonReporter())->generate($result),
        };

        if ($output) {
            file_put_contents($output, $payload);
        } else {
            @mkdir('report', 0775, true);
            file_put_contents('report/easyaudit-report.' . ($format === 'sarif' ? 'sarif' : 'json'), $payload);
        }
        echo "report was written\n";
        echo "it can be found at " . ($output ?: 'report/easyaudit-report.' . ($format === 'sarif' ? 'sarif' : 'json')) . "\n";

        $errors   = (int)($result['summary']['errors']   ?? 0);
        $warnings = (int)($result['summary']['warnings'] ?? 0);
        return $errors ? 2 : ($warnings ? 1 : 0);
    }
}
