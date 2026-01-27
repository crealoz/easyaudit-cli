<?php

namespace EasyAudit\Exception;

/**
 * Thrown when the middleware requires a CLI upgrade.
 *
 * HTTP 426 Upgrade Required indicates:
 * - CLI version is too old
 * - CLI version is unregistered
 * - CLI PHAR hash doesn't match registered version
 */
class UpgradeRequiredException extends \RuntimeException
{
    private string $currentVersion;
    private ?string $minimumVersion;

    public function __construct(
        string $currentVersion,
        ?string $minimumVersion = null,
        string $message = ''
    ) {
        $this->currentVersion = $currentVersion;
        $this->minimumVersion = $minimumVersion;

        if ($message === '') {
            if ($minimumVersion !== null) {
                $message = "CLI upgrade required. Current: v{$currentVersion}, minimum: v{$minimumVersion}";
            } else {
                $message = "CLI upgrade required. Current version (v{$currentVersion}) is not recognized by the server.";
            }
        }

        parent::__construct($message);
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    public function getMinimumVersion(): ?string
    {
        return $this->minimumVersion;
    }
}
