<?php
namespace EasyAudit\Support;

use EasyAudit\Exception\EnvAuthException;
use EasyAudit\Exception\GitHubAuthException;

final class Env
{
    public static function getAuthHeader(): ?string
    {
        $authHeader = null;
        // If GitHub is used, the secret vault will store credentials and we will use them here to authenticate.
        if (Env::isGithubActions()) {
            $authHeader = getenv('EASYAUDIT_AUTH');
            if ($authHeader === false || $authHeader === '') {
                throw new GitHubAuthException('EASYAUDIT_AUTH environment variable is not set or empty.');
            }
            if (!str_contains($authHeader, ':')) {
                throw new GitHubAuthException('EASYAUDIT_AUTH environment variable is malformed.');
            }
            if (!str_contains($authHeader, 'Bearer ')) {
                $authHeader = 'Bearer ' . $authHeader;
            }
        } else {
            $token = Env::getStoredCredentials();
            if ($token === null || $token === '') {
                throw new EnvAuthException('No API credential found.');
            }
            $authHeader = 'Bearer ' . $token;
        }
        return $authHeader;
    }

    public static function storeCredentials(string $key, string $hash): void
    {
        Paths::updateConfigFile([
            'key'  => $key,
            'hash' => $hash,
        ]);
    }

    public static function getStoredCredentials(): ?string
    {
        $f = Paths::configFile();
        if (!is_file($f) || !is_readable($f)) {
            return null;
        }
        $content = file_get_contents($f);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['key']) || !isset($data['hash'])) {
            return null;
        }
        return $data['key'] . ':' . $data['hash'];
    }

    /**
     * Determine if self-signed certificates should be accepted.
     * If environment variable EASYAUDIT_SELF_SIGNED is set, use its boolean value.
     * Otherwise, if running in GitHub Actions (GITHUB_ACTIONS=true), return false
     * Finally, fall back to the CI environment variable (CI=true/false).
     * If nothing is set, return false.
     */
    public static function isSelfSigned(): bool
    {
        return filter_var(Paths::getConfig('use-self-signed'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function isGithubActions(): bool
    {
        return filter_var(getenv('GITHUB_ACTIONS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public static function getApiUrl(): string
    {
        $customUrl = Paths::getConfig('api-url');
        if ($customUrl !== false && $customUrl !== '') {
            return rtrim($customUrl, '/') . '/';
        }
        return 'https://api.crealoz.fr/';
    }
}
