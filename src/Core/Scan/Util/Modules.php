<?php

namespace EasyAudit\Core\Scan\Util;

class Modules
{
    /**
     * Extract module name (Vendor_Module) from a file path.
     *
     * Supports:
     * - app/code/Vendor/Module/...
     * - vendor/vendor-name/module-name/...
     * - Generic Vendor/Module/Block|Model|... patterns
     */
    public static function extractModuleNameFromPath(string $filePath): ?string
    {
        $filePath = str_replace('\\', '/', $filePath);

        // Pattern 1: app/code/Vendor/Module
        if (preg_match('#/app/code/([A-Z][a-zA-Z0-9]+)/([A-Z][a-zA-Z0-9]+)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        // Pattern 2: vendor/vendor-name/module-name or vendor/vendor-name/magento2-module
        $vendorPattern = '#/vendor/([a-z0-9-]+)/(?:magento2?-)?([a-z0-9-]+)/#i';
        if (preg_match($vendorPattern, $filePath, $matches)) {
            $vendor = str_replace(' ', '', ucwords(str_replace('-', ' ', $matches[1])));
            $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $matches[2])));
            return $vendor . '_' . $module;
        }

        // Pattern 3: Generic two-level directory structure that looks like modules
        $genericPattern = '#/([A-Z][a-zA-Z0-9]*)/([A-Z][a-zA-Z0-9]*)/'
            . '(?:Block|Model|ViewModel|Controller|Helper)/#';
        if (preg_match($genericPattern, $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        return null;
    }

    /**
     * Group an array of file paths by their module name.
     */
    public static function groupFilesByModule(array $files): array
    {
        $moduleFiles = [];

        foreach ($files as $file) {
            $moduleName = self::extractModuleNameFromPath($file);

            if ($moduleName === null) {
                continue;
            }

            if (!isset($moduleFiles[$moduleName])) {
                $moduleFiles[$moduleName] = [];
            }

            $moduleFiles[$moduleName][] = $file;
        }

        return $moduleFiles;
    }

    /**
     * Check if two classes belong to the same module (same Vendor\Module prefix).
     */
    public static function isSameModule(string $classA, string $classB): bool
    {
        $partsA = explode('\\', $classA);
        $partsB = explode('\\', $classB);

        return isset($partsA[1], $partsB[1])
            && $partsA[0] === $partsB[0]
            && $partsA[1] === $partsB[1];
    }

    /**
     * Check if a file is in a Block directory.
     */
    public static function isBlockFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        return preg_match('#/Block/[^/]+\.php$#', $file) === 1;
    }

    /**
     * Check if a file is in a Setup directory.
     */
    public static function isSetupDirectory(string $filePath): bool
    {
        return str_contains($filePath, '/Setup/') ||
               str_contains($filePath, DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR);
    }

    /**
     * Find the di.xml file for a given PHP file by walking up directories.
     */
    public static function findDiXmlForFile(string $phpFile): ?string
    {
        // Look for app/code/Vendor/Module pattern
        if (preg_match('#(.*?/app/code/[^/]+/[^/]+)/#', $phpFile, $matches)) {
            $moduleRoot = $matches[1];
            $diFile = $moduleRoot . '/etc/di.xml';
            if (file_exists($diFile)) {
                return $diFile;
            }
        }

        // Fallback: walk up directories looking for etc/di.xml
        $dir = dirname($phpFile);
        while ($dir !== '/' && $dir !== '.') {
            $diFile = $dir . '/etc/di.xml';
            if (file_exists($diFile)) {
                return $diFile;
            }
            if (str_ends_with($dir, '/app/code')) {
                break;
            }
            $dir = dirname($dir);
        }

        return null;
    }
}
