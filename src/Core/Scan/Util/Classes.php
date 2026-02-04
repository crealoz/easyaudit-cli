<?php

namespace EasyAudit\Core\Scan\Util;

class Classes
{
    public static function parseImportedClasses(string $fileContent): array {
        $importedClasses = [];
        if (preg_match_all('/use\s+([^;]+);/', $fileContent, $matches)) {
            foreach ($matches[1] as $import) {
                if (str_contains($import, ' as ')) {
                    $parts = explode(' as ', $import);
                    $importedClasses[trim(end($parts))] = trim($parts[0]);
                    continue;
                }
                $parts = explode('\\', $import);
                $importedClasses[trim(end($parts))] = trim($import);
            }
        }
        return $importedClasses;
    }

    public static function parseConstructorParameters(string $fileContent): array {
        $constructorParameters = [];
        if ($fileContent !== false && str_contains($fileContent, '__construct') && preg_match('/function\s+__construct\s*\(([^)]*)\)/', $fileContent, $m)) {
            $constructorParameters = array_map('trim', explode(',', $m[1]));
        }
        return $constructorParameters;
    }

    public static function consolidateParameters(array $constructorParameters, array $importedClasses): array {
        $consolidatedParameters = [];
        foreach ($constructorParameters as $parameter) {
            $paramParts = explode(' ', $parameter);
            if (empty($paramParts)) {
                continue;
            }
            $paramName = trim(end($paramParts));
            $paramClass = null;
            foreach ($paramParts as $part) {
                if (in_array($part, ['protected', 'private', 'public', 'readonly', '?', $paramName])) {
                    continue;
                }
                $paramClass = trim($part);
                break;
            }
            if (isset($importedClasses[$paramClass])) {
                $consolidatedParameters[$paramName] = $importedClasses[$paramClass];
            }
        }
        return $consolidatedParameters;
    }

    /**
     * Resolve a fully qualified class name to its file path.
     *
     * Tries two strategies:
     * 1. Full path from scan root (works for full Magento installs)
     * 2. Strip Vendor\Module prefix (works for single module scans)
     *
     * @param string $className Fully qualified class name (e.g., Vendor\Module\Console\Command)
     * @return string|null The resolved file path, or null if not found
     */
    public static function resolveClassToFile(string $className): ?string
    {
        // Strategy 1: Full path from scan root (full Magento install)
        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        $fullPath = EA_SCAN_PATH . DIRECTORY_SEPARATOR . $classPath;
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        // Strategy 2: Strip Vendor\Module prefix (single module scan)
        $parts = explode('\\', $className);
        if (count($parts) > 2) {
            $relativeClass = implode(DIRECTORY_SEPARATOR, array_slice($parts, 2)) . '.php';
            $relativePath = EA_SCAN_PATH . DIRECTORY_SEPARATOR . $relativeClass;
            if (file_exists($relativePath)) {
                return $relativePath;
            }
        }

        return null;
    }
}