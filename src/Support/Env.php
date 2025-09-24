<?php
namespace EasyAudit\Support;

final class Env
{
    public static function getToken(): ?string
    {
        $fromEnv = getenv('EASYAUDIT_TOKEN');
        if ($fromEnv && $fromEnv !== '') {
            return $fromEnv;
        }

        $file = Paths::configFile();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $json = json_decode($raw, true);
                if (is_array($json) && !empty($json['token'])) {
                    return (string)$json['token'];
                }
            }
        }
        return null;
    }

    public static function saveToken(string $token): bool
    {
        $dir = Paths::configDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $data = json_encode(['token' => $token], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return @file_put_contents(Paths::configFile(), $data, LOCK_EX) !== false;
    }

    public static function deleteToken(): void
    {
        $f = Paths::configFile();
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
