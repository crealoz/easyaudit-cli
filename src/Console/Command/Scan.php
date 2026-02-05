<?php

namespace EasyAudit\Console\Command;

use EasyAudit\Console\CommandInterface;
use EasyAudit\Console\Util\Args;
use EasyAudit\Core\Scan\ExternalToolMapping;
use EasyAudit\Core\Scan\Scanner;
use EasyAudit\Core\Report\JsonReporter;
use EasyAudit\Core\Report\SarifReporter;
use EasyAudit\Service\CliWriter;
use EasyAudit\Support\ProjectIdentifier;

final class Scan implements CommandInterface
{
    public function getDescription(): string
    {
        return 'Perform a security scan on Magento 2 codebase';
    }

    public function getSynopsis(): string
    {
        return 'scan [options] <path>';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit scan [options] <path>

Scan a Magento 2 codebase for anti-patterns, code quality issues, and security problems.

Arguments:
  <path>                       Path to scan (default: current directory)

Options:
  --format=<format>            Output format: json, sarif (default: json)
  --exclude=<patterns>         Comma-separated list of glob patterns to exclude
  --exclude-ext=<exts>         Comma-separated list of file extensions to exclude (e.g. .log,.tmp)
  --output=<file>              Output file path. Default: report/easyaudit-report.<format>
  --project-name=<name>        Explicit project identifier (slug)
  -h, --help                   Show this help message

Examples:
  easyaudit scan /path/to/magento
  easyaudit scan --format=sarif --output=report.sarif .
  easyaudit scan --exclude="vendor,generated" /path/to/magento
HELP;
    }

    public function run(array $argv): int
    {
        // if option is help, show help
        if (Args::optBool(Args::parse($argv)[0], 'help')) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }

        [$opts, $rest] = Args::parse($argv);
        $format      = strtolower(Args::optStr($opts, 'format', 'json')) ?? 'json';

        $allowedFormats = ['json', 'sarif'];
        if (!in_array($format, $allowedFormats, true)) {
            CliWriter::errorToStderr("Error: Unknown format '$format'. Allowed formats: " . implode(', ', $allowedFormats));
            return 1;
        }

        $exclude     = Args::optStr($opts, 'exclude', '');
        $output      = Args::optStr($opts, 'output');
        $excludedExt = Args::optArr($opts, 'exclude-ext');
        $projectName = Args::optStr($opts, 'project-name');
        $path        = $rest ?: '.';
        define('EA_SCAN_PATH', $path);

        $scanner  = new Scanner();
        $result   = $scanner->run($exclude, $excludedExt);

        $findings = $result['findings'];
        $toolSuggestions = $result['toolSuggestions'];

        // Add metadata for fix-apply to use
        $scanPathResolved = realpath($path) ?: $path;
        $findings['metadata'] = [
            'scan_path' => $scanPathResolved,
        ];
        if ($projectName !== null && $projectName !== '') {
            $findings['metadata']['project_id'] = ProjectIdentifier::resolve($projectName, $scanPathResolved);
        }

        $payload = match ($format) {
            'sarif' => (new SarifReporter())->generate($findings),
            'json' => (new JsonReporter())->generate($findings),
        };

        if ($output) {
            file_put_contents($output, $payload);
        } else {
            @mkdir('report', 0775, true);
            file_put_contents('report/easyaudit-report.' . ($format === 'sarif' ? 'sarif' : 'json'), $payload);
        }
        CliWriter::line("Report was written to " . ($output ?: 'report/easyaudit-report.' . ($format === 'sarif' ? 'sarif' : 'json')));

        // Print tool suggestions for issues that can be fixed by external tools
        if (!empty($toolSuggestions)) {
            CliWriter::section("External tool suggestions:");
            foreach ($toolSuggestions as $ruleId => $count) {
                $description = ExternalToolMapping::getDescription($ruleId) ?? $ruleId;
                $command = ExternalToolMapping::getCommand($ruleId);
                CliWriter::line("  $count $description found - run: " . CliWriter::green($command));
            }
        }

        $errors   = (int)($findings['summary']['errors']   ?? 0);
        $warnings = (int)($findings['summary']['warnings'] ?? 0);
        return $errors ? 2 : ($warnings ? 1 : 0);
    }
}
