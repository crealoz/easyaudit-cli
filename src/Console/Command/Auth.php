<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Confirm;
use EasyAudit\Support\Env;

final class Auth implements \EasyAudit\Console\CommandInterface
{
    private function askSecret(): string
    {
        fwrite(STDOUT, 'Enter your EasyAudit token');
        shell_exec('stty -echo');
        $val = trim(fgets(STDIN) ?: '');
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $val;
    }

    public function run(array $argv): int
    {
        // non-interactive mode: --token=...
        foreach ($argv as $a) {
            if (str_starts_with($a, '--token=')) {
                $tok = substr($a, 8);
                if ($tok === '') { fwrite(STDERR, "Empty token.\n"); return 64; }
                if (!Env::saveToken($tok)) { fwrite(STDERR,"Failed to write config.\n"); return 74; }
                fwrite(STDOUT, "Token saved.\n");
                return 0;
            }
        }

        // interactive
        $token = $this->askSecret();
        if ($token === '') { fwrite(STDERR, "Empty token. Aborting.\n"); return 64; }

        if (!Confirm::confirm("Save token? [Y/n]:")) {
            fwrite(STDOUT, "Cancelled.\n");
            return 0;
        }

        if (!Env::saveToken($token)) {
            fwrite(STDERR, "Could not write ~/.config/easyaudit/config.json\n");
            return 74;
        }
        fwrite(STDOUT, "Token saved. Tip: EASYAUDIT_TOKEN can override local auth.\n");
        return 0;
    }
}
