<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Support\Env;

final class Credits implements \EasyAudit\Console\CommandInterface
{
    public function getDescription(): string
    {
        return 'Display your remaining credits';
    }

    public function getSynopsis(): string
    {
        return 'credits';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit credits

Display your remaining EasyAudit API credits.

Options:
  -h, --help               Show this help message
HELP;
    }

    public function run(array $argv): int
    {
        [$opts, ] = Args::parse($argv);
        if (Args::optBool($opts, 'help')) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }

        $token = Env::getAuthHeader();
        if (!$token) {
            fwrite(STDERR, "No token found. Run `auth:login` or set EASYAUDIT_TOKEN.\n");
            return 64;
        }

        // TODO: call API to fetch credits
        fwrite(STDOUT, "Token present. (Replace with real API call)\n");
        return 0;
    }
}
