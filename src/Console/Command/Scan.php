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
        [$opts, $rest] = Args::parse($argv);
        $format   = strtolower(Args::optStr($opts, 'format', 'json')) ?? 'json';
        $exclude  = Args::optStr($opts, 'exclude', '');
        $output   = Args::optStr($opts, 'output');
        $excludedExt = Args::optArr($opts, 'exclude-ext');
        $path    = $rest ?: '.';

        $scanner  = new Scanner();
        $result   = $scanner->run($path, $exclude, $excludedExt);

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

        $errors   = (int)($result['summary']['errors']   ?? 0);
        $warnings = (int)($result['summary']['warnings'] ?? 0);
        return $errors ? 2 : ($warnings ? 1 : 0);
    }
}
