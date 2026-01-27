<?php

namespace EasyAudit;

/**
 * Version information for the CLI.
 *
 * VERSION and HASH constants are replaced during CI builds.
 * - VERSION: Semantic version (e.g., "0.1.0")
 * - HASH: SHA-512 hash of the built PHAR file
 *
 * When running from source (not PHAR), both are "dev".
 */
class Version
{
    public const VERSION = 'dev';
    public const HASH = 'dev';

    /**
     * Get formatted version string for display.
     */
    public static function getVersionString(): string
    {
        $version = self::VERSION;
        $hash = self::HASH;

        if ($version === 'dev') {
            return 'EasyAudit CLI (development version)';
        }

        $shortHash = substr($hash, 0, 12);
        return "EasyAudit CLI v{$version} (hash: {$shortHash}...)";
    }

    /**
     * Check if running from a built PHAR (vs source).
     */
    public static function isReleaseBuild(): bool
    {
        return self::VERSION !== 'dev';
    }
}
