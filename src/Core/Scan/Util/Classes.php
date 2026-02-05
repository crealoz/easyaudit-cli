<?php

namespace EasyAudit\Core\Scan\Util;

use EasyAudit\Exception\Scanner\InstantiationNotFoundException;
use EasyAudit\Exception\Scanner\NoChildrenException;
use EasyAudit\Service\CliWriter;

class Classes
{
    private static array $hierarchy = ['children' => [], 'classToFile' => []];
    private static array $processedFiles = [];
    /**
     * Basic PHP types
     */
    public const BASIC_TYPES = ['string', 'int', 'float', 'bool', 'array', 'mixed'];

    public static function parseImportedClasses(string $fileContent): array
    {
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

    public static function hasImportedClass(string $class, string $fileContent): bool
    {
        $importedClasses = self::parseImportedClasses($fileContent);
        return in_array($class, $importedClasses);
    }

    public static function hasImportedClasses(array $classes, string $fileContent): bool
    {
        foreach ($classes as $class) {
            if (self::hasImportedClass($class, $fileContent)) {
                return true;
            }
        }
        return false;
    }

    public static function parseConstructorParameters(string $fileContent): array
    {
        $constructorParameters = [];
        $pattern = '/function\s+__construct\s*\(([^)]*)\)/';
        if ($fileContent !== false && str_contains($fileContent, '__construct') && preg_match($pattern, $fileContent, $m)) {
            $constructorParameters = array_map('trim', explode(',', $m[1]));
        }
        return $constructorParameters;
    }

    /**
     * Extract parameter types from constructor
     *
     * @param string $fileContent
     * @return array List of type names (short and fully qualified)
     */
    public static function getConstructorParameterTypes(string $fileContent): array
    {

        $imports = self::parseImportedClasses($fileContent);
        $constructorParams = self::parseConstructorParameters($fileContent);

        return self::consolidateParameters($constructorParams, $imports);
    }

    /**
     * Checks for the instantiation of the param as promoted or not in constructor to retrieve class property
     * @param string $param
     * @return string
     */
    public static function getInstantiation(array $constructorParams, string $trackedParam, string $fileContent): ?string
    {
        foreach ($constructorParams as $constructorParam) {
            if (str_contains($constructorParam, $trackedParam)) {
                $cleanParam = ltrim($trackedParam, '$');
                // The tracked param is promoted. Therefore, property is the same as param.
                if (preg_match('/\b(private|protected|public)\b/', $constructorParam)) {
                    return '$this->' . $cleanParam;
                }
                $pattern = '/\$this->(\w+)\s*=\s*\$' . preg_quote($cleanParam, '/') . '\s*[;,)]/';
                if (preg_match($pattern, $fileContent, $match)) {
                    return '$this->' . $match[1];
                }
                throw new InstantiationNotFoundException();
            }
        }
        return null;
    }

    public static function consolidateParameters(array $constructorParameters, array $importedClasses): array
    {
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
            if (empty($paramClass) || in_array($paramClass, self::BASIC_TYPES, true)) {
                continue;
            }
            if (isset($importedClasses[$paramClass])) {
                $paramClass = '\\' . $importedClasses[$paramClass];
            }
            $consolidatedParameters[$paramName] = $paramClass;
        }
        return $consolidatedParameters;
    }

    /**
     * Resolve a short class name to its fully qualified class name using file content.
     *
     * Checks use statements for direct imports or aliases.
     * Falls back to assuming the class is in the same namespace.
     *
     * @param string $shortName   The short class name (e.g., "Collection")
     * @param string $fileContent The file content to search for use statements
     * @param string $namespace   The current namespace as fallback
     */
    public static function resolveShortClassName(string $shortName, string $fileContent, string $namespace): string
    {
        if (str_contains($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        if (
            preg_match('/use\s+([^;]+\\\\' . preg_quote($shortName, '/') . ')\s*;/', $fileContent, $useMatch) ||
            preg_match('/use\s+([^;]+)\s+as\s+' . preg_quote($shortName, '/') . '\s*;/', $fileContent, $useMatch)
        ) {
            return trim($useMatch[1]);
        }

        return $namespace . '\\' . $shortName;
    }

    /**
     * Build a class hierarchy map from PHP files.
     * Stores the result internally for use by getChildren().
     */
    public static function buildClassHierarchy(array $phpFiles): void
    {
        foreach ($phpFiles as $file) {
            if (isset(self::$processedFiles[$file])) {
                continue;
            }
            self::$processedFiles[$file] = true;

            $content = @file_get_contents($file);
            if (($content === false) || (!preg_match('/namespace\s+([^;]+);/', $content, $nsMatch))) {
                continue;
            }
            $namespace = trim($nsMatch[1]);

            if (!preg_match('/class\s+(\w+)\s+extends\s+([^\s{]+)/', $content, $classMatch)) {
                continue;
            }

            $className = $namespace . '\\' . $classMatch[1];
            $parentShortName = trim($classMatch[2]);

            self::$hierarchy['classToFile'][$className] = $file;
            $parentFqcn = self::resolveShortClassName($parentShortName, $content, $namespace);

            self::$hierarchy['children'][$parentFqcn][] = $className;
        }
    }

    /**
     * Get the list of children for a class.
     * @throws NoChildrenException
     */
    public static function getChildren(string $className): array
    {
        if (empty(self::$hierarchy['children'][$className])) {
            throw new NoChildrenException();
        }
        return self::$hierarchy['children'][$className] ?? [];
    }

    /**
     * Extract the fully qualified class name from file content
     */
    public static function extractClassName(string $fileContent): string
    {
        if (preg_match('/namespace\s+([^;]+);/', $fileContent, $namespaceMatch)) {
            $namespace = trim($namespaceMatch[1]);
            if (preg_match('/class\s+(\w+)/', $fileContent, $classMatch)) {
                return $namespace . '\\' . $classMatch[1];
            }
        }
        return 'UnknownClass';
    }

    /**
     * Extract parameter names that are passed to parent::__construct()
     */
    public static function getParentConstructorParams(string $fileContent): array
    {
        if (!preg_match('/parent\s*::\s*__construct\s*\(([^)]*)\)/s', $fileContent, $match)) {
            return [];
        }

        $params = [];
        if (preg_match_all('/\$(\w+)/', $match[1], $varMatches)) {
            $params = $varMatches[1];
        }

        return $params;
    }

    /**
     * Resolve a fully qualified class name to its file path.
     *
     * Tries two strategies:
     * 1. Full path from scan root (works for full Magento installs)
     * 2. Strip Vendor\Module prefix (works for single module scans)
     *
     * @param  string $className Fully qualified class name (e.g., Vendor\Module\Console\Command)
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
