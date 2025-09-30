<?php
namespace EasyAudit\Console\Command;

use EasyAudit\Support\Env;

final class Credits implements \EasyAudit\Console\CommandInterface
{
    public function run(array $argv): int
    {
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
