<?php

namespace EasyAudit\Console\Util;

class Filenames
{
    /**
     * Sanitize a file path to create a valid patch filename.
     *
     * @param  string $filePath Original file path
     * @return string Sanitized filename (without extension)
     */
    public static function sanitize(string $filePath): string
    {
        // Remove leading slashes
        $filename = ltrim($filePath, '/');

        // Replace path separators with underscores
        $filename = str_replace(['/', '\\'], '_', $filename);

        // Remove .php or .xml extension (will add .patch)
        return preg_replace('/\.(php|xml)$/', '', $filename);
    }

    /**
     * Get path relative to project root, without extension.
     *
     * Example: "/var/www/magento/app/code/Vendor/Module/Model/MyClass.php"
     *       -> "app/code/Vendor/Module/Model/MyClass"
     *
     * @param  string $filePath    Absolute file path
     * @param  string $projectRoot Project root path
     * @return string Relative path without extension
     */
    public static function getRelativePath(string $filePath, string $projectRoot): string
    {
        // Normalize paths
        $filePath = rtrim($filePath, '/');
        $projectRoot = rtrim($projectRoot, '/');

        // Remove project root prefix if present
        if (str_starts_with($filePath, $projectRoot . '/')) {
            $relativePath = substr($filePath, strlen($projectRoot) + 1);
        } else {
            // If not under project root, use full path without leading slash
            $relativePath = ltrim($filePath, '/');
        }

        // Remove .php or .xml extension
        return preg_replace('/\.(php|xml)$/', '', $relativePath);
    }

    /**
     * Add sequence suffix if path already exists.
     *
     * Example: "MyClass" + existing ["MyClass.patch"] -> "MyClass-2.patch"
     *
     * @param  string $basePath  Base path without .patch extension
     * @param  string $targetDir Target directory where patches are saved
     * @return string Path with sequence suffix if needed (includes .patch extension)
     */
    public static function getSequencedPath(string $basePath, string $targetDir): string
    {
        $targetDir = rtrim($targetDir, '/');
        $patchPath = $targetDir . '/' . $basePath . '.patch';

        if (!file_exists($patchPath)) {
            return $basePath . '.patch';
        }

        // Find next available sequence number
        $sequence = 2;
        while (file_exists($targetDir . '/' . $basePath . '-' . $sequence . '.patch')) {
            $sequence++;
        }

        return $basePath . '-' . $sequence . '.patch';
    }
}
