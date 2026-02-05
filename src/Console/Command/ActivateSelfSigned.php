<?php

namespace EasyAudit\Console\Command;

use EasyAudit\Console\CommandInterface;
use EasyAudit\Console\Util\Args;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Paths;

class ActivateSelfSigned implements CommandInterface
{
    public function getDescription(): string
    {
        return 'Toggle self-signed certificate support';
    }

    public function getSynopsis(): string
    {
        return 'activate-self-signed';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit activate-self-signed

Toggle self-signed certificate support for API connections.
Useful when working behind corporate proxies or in development environments.

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

        // Logic to activate self-signed certificates
        if (Confirm::confirm("Do you want to activate self-signed certificates? (y/n): ")) {
            Paths::updateConfigFile(['use-self-signed' => true]);
            fwrite(STDOUT, "Activating self-signed certificates...\n");
            fwrite(STDOUT, "Self-signed certificates activated.\n");
            return 0;
        } else {
            Paths::updateConfigFile(['use-self-signed' => false]);
            fwrite(STDOUT, "Operation cancelled. If self-signed certificates were enabled, it has been cancelled.\n");
            return 1;
        }
    }
}
