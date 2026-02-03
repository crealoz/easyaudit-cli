<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Console\Util\Args;
use EasyAudit\Support\Env;

final class Auth implements \EasyAudit\Console\CommandInterface
{
    public function getDescription(): string
    {
        return 'Authenticate with the EasyAudit service';
    }

    public function getSynopsis(): string
    {
        return 'auth [--key=<key> --hash=<hash>]';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage: easyaudit auth [options]

Authenticate with the EasyAudit service. Credentials are stored in ~/.config/easyaudit/config.json.

Options:
  --key=<key>              API key (non-interactive mode)
  --hash=<hash>            API hash (non-interactive mode)
  -h, --help               Show this help message

Examples:
  easyaudit auth                           # Interactive mode
  easyaudit auth --key=abc123 --hash=xyz   # Non-interactive mode
HELP;
    }

    private function askSecret(): array
    {
        fwrite(STDOUT, 'Enter your EasyAudit key');
        shell_exec('stty -echo');
        $key = trim(fgets(STDIN) ?: '');
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, 'Enter your EasyAudit hash');
        shell_exec('stty -echo');
        $hash = trim(fgets(STDIN) ?: '');
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return [
            'key' => $key,
            'hash' => $hash
        ];
    }

    public function run(array $argv): int
    {
        [$opts, ] = Args::parse($argv);
        if (Args::optBool($opts, 'help')) {
            fwrite(STDOUT, $this->getHelp() . "\n");
            return 0;
        }

        // non-interactive mode: --key=... --hash=...
        $key = null;
        $hash = null;
        foreach ($argv as $a) {
            if (str_starts_with($a, '--key=')) {
                $key = substr($a, 8);
                if ($key === '') {
                    fwrite(STDERR, "Empty token.\n");
                    return 64;
                }
            }
            if (str_starts_with($a, '--hash=')) {
                $hash = substr($a, 7);
                if ($hash === '') {
                    fwrite(STDERR, "Empty hash.\n");
                    return 64;
                }
            }
        }

        if ($key === null && $hash === null) {
            $credentials = $this->askSecret();
            $key = $credentials['key'];
            $hash = $credentials['hash'];
        }

        // interactive
        try {
            Env::storeCredentials($key, $hash);
        } catch (\Exception $e) {
            fwrite(STDERR, "Could not write ~/.config/easyaudit/config.json\n");
            return 74;
        }
        fwrite(STDOUT, "Auth saved.\n");
        return 0;
    }
}
