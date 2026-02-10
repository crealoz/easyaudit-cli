<?php

namespace EasyAudit\Service;

use EasyAudit\Exception\EnvAuthException;
use EasyAudit\Exception\GitHubAuthException;

final class Env
{
    /**
     * Get the authorization header for API requests.
     * If running in GitHub Actions, use the EASYAUDIT_AUTH environment variable.
     * Otherwise, use stored credentials from the config file.
     *
     * @return string|null The authorization header or null if not found.
     * @throws GitHubAuthException If the EASYAUDIT_AUTH variable is missing or malformed in GitHub Actions.
     * @throws EnvAuthException If no stored credentials are found when not in GitHub Actions.
     */
    public static function getAuthHeader(): ?string
    {
        // If GitHub is used, the secret vault will store credentials and we will use them here to authenticate.
        if (self::isGithubActions()) {
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
            $authHeader = 'Bearer ' . $token;
        }
        return $authHeader;
    }

    /**
     * Store API credentials in the config file.
     *
     * @param string $key  The API key.
     * @param string $hash The API hash.
     */
    public static function storeCredentials(string $key, string $hash): void
    {
        Paths::updateConfigFile(
            [
            'key'  => $key,
            'hash' => $hash,
            ]
        );
    }

    /**
     * Retrieve stored API credentials from the config file.
     *
     * @return string The credentials in "key:hash" format or null if not found or malformed.
     * @throws EnvAuthException
     */
    public static function getStoredCredentials(): string
    {
        $f = Paths::configFile();
        if (!is_file($f) || !is_readable($f)) {
            echo RED . "Config file not found or not readable: $f " . RESET . " \n";
            throw new EnvAuthException('No API credential found.');
        }
        $content = file_get_contents($f);
        if ($content === false) {
            echo RED . "Failed to read config file: $f" . RESET . "\n";
            throw new EnvAuthException('No API credential found.');
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['key']) || !isset($data['hash'])) {
            echo RED . "Config file is malformed: $f" . RESET . "\n";
            throw new EnvAuthException('No API credential found.');
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

    /**
     * Check if the code is running in a GitHub Actions environment.
     * This is determined by the presence of the GITHUB_ACTIONS environment variable set to true.
     *
     * @return bool True if running in GitHub Actions, false otherwise.
     */
    public static function isGithubActions(): bool
    {
        return filter_var(getenv('GITHUB_ACTIONS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the API URL from the configuration file or return the default URL.
     *
     * @return string The API URL.
     */
    public static function getApiUrl(): string
    {
        $customUrl = Paths::getConfig('api-url');
        if ($customUrl !== false && $customUrl !== '') {
            return rtrim($customUrl, '/') . '/';
        }
        return 'https://api.crealoz.fr:8443/';
    }
}
