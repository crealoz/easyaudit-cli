<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Core\Scan\Scanner;
use EasyAudit\Core\Report\JsonReporter;
use EasyAudit\Core\Report\SarifReporter;

final class ScanCommand
{
    public function run(array $argv): int
    {
        [$opts, $rest] = Args::parse($argv);
        $format   = strtolower(Args::optStr($opts, 'format', 'json')) ?? 'json';
        $exclude  = Args::optArr($opts, 'exclude');
        $output   = Args::optStr($opts, 'output', null);
        $paths    = $rest ?: ['.'];

        $scanner  = new Scanner($paths, $exclude);
        $result   = $scanner->run();

        $payload = match ($format) {
            'sarif' => (new SarifReporter())->generate($result),
            'json' => (new JsonReporter())->generate($result),
        };

        if ($output) {
            file_put_contents($output, $payload);
        }
        else {
            echo $payload;
        }

        $errors   = (int)($result['summary']['errors']   ?? 0);
        $warnings = (int)($result['summary']['warnings'] ?? 0);
        return $errors ? 2 : ($warnings ? 1 : 0);
    }
}
