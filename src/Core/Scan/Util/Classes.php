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
}