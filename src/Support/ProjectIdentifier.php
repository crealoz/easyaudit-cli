<?php

namespace EasyAudit\Support;

final class ProjectIdentifier
{
    /**
     * Resolve project identifier with datetime suffix.
     * Priority: CLI argument → composer.json → module.xml → uniqid
     *
     * @param  string|null $cliArg   Explicit project name from CLI
     * @param  string      $scanPath Path to scan directory for auto-detection
     * @return string Project identifier with datetime suffix (e.g., my-project-20260114-145655)
     */
    public static function resolve(?string $cliArg, string $scanPath): string
    {
        $baseName = self::resolveBaseName($cliArg, $scanPath);
        $datetime = date('Ymd-His');

        return $baseName . '-' . $datetime;
    }

    /**
     * Resolve base project name without datetime suffix.
     */
    private static function resolveBaseName(?string $cliArg, string $scanPath): string
    {
        // 1. CLI argument takes precedence
        if ($cliArg !== null && $cliArg !== '') {
            return self::slugify($cliArg);
        }

        // 2. Try composer.json
        $composerName = self::fromComposer($scanPath);
        if ($composerName !== null) {
            return self::slugify($composerName);
        }

        // 3. Try module.xml (find first one)
        $moduleName = self::fromModuleXml($scanPath);
        if ($moduleName !== null) {
            return self::slugify($moduleName);
        }

        // 4. Fallback to uniqid
        return 'project-' . substr(uniqid(), -8);
    }

    /**
     * Extract project name from composer.json.
     * Checks root and Magento app/code directories.
     */
    private static function fromComposer(string $path): ?string
    {
        $path = rtrim($path, '/');

        // Check root composer.json first
        $composerFile = $path . '/composer.json';
        if (is_file($composerFile)) {
            $name = self::parseComposerName($composerFile);
            if ($name !== null) {
                return $name;
            }
        }

        // Check Magento app/code/*/composer.json pattern
        $appCodePath = $path . '/app/code';
        if (is_dir($appCodePath)) {
            $vendors = @scandir($appCodePath);
            if ($vendors !== false) {
                foreach ($vendors as $vendor) {
                    if ($vendor === '.' || $vendor === '..') {
                        continue;
                    }
                    $vendorPath = $appCodePath . '/' . $vendor;
                    if (!is_dir($vendorPath)) {
                        continue;
                    }
                    $modules = @scandir($vendorPath);
                    if ($modules === false) {
                        continue;
                    }
                    foreach ($modules as $module) {
                        if ($module === '.' || $module === '..') {
                            continue;
                        }
                        $moduleComposer = $vendorPath . '/' . $module . '/composer.json';
                        if (is_file($moduleComposer)) {
                            $name = self::parseComposerName($moduleComposer);
                            if ($name !== null) {
                                return $name;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse composer.json and extract the name field.
     */
    private static function parseComposerName(string $file): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return null;
        }
        return $data['name'];
    }

    /**
     * Extract module name from first module.xml found.
     */
    private static function fromModuleXml(string $path): ?string
    {
        $path = rtrim($path, '/');

        // Search in common Magento module locations
        $patterns = [
            $path . '/etc/module.xml',
            $path . '/app/code/*/*/etc/module.xml',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if ($files !== false && !empty($files)) {
                foreach ($files as $file) {
                    $name = self::parseModuleXml($file);
                    if ($name !== null) {
                        return $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse module.xml and extract the module name attribute.
     */
    private static function parseModuleXml(string $file): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Simple regex to extract module name (avoid XML parsing overhead)
        if (preg_match('/<module[^>]+name=["\']([^"\']+)["\']/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert a string to a URL-friendly slug.
     * Max length: 32 characters.
     */
    private static function slugify(string $name): string
    {
        // Lowercase
        $slug = strtolower($name);

        // Replace slashes, underscores, spaces with dashes
        $slug = str_replace(['/', '_', ' '], '-', $slug);

        // Remove non-alphanumeric characters except dashes
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

        // Collapse multiple dashes
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim dashes from ends
        $slug = trim($slug, '-');

        // Limit to 32 characters
        if (strlen($slug) > 32) {
            $slug = substr($slug, 0, 32);
            $slug = rtrim($slug, '-');
        }

        return $slug ?: 'project';
    }
}
