<?php

namespace EasyAudit\Console\Command;

use EasyAudit\Console\CommandInterface;
use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Paths;

class ActivateSelfSigned implements CommandInterface
{
    public function run(array $argv): int
    {
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