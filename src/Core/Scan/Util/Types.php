<?php

namespace EasyAudit\Core\Scan\Util;

use ReflectionClass;
use ReflectionException;

class Types
{
    /**
     * Known non-Magento PHP library vendor prefixes.
     */
    private const NON_MAGENTO_VENDORS = [
        'GuzzleHttp\\', 'Monolog\\', 'Psr\\', 'Symfony\\', 'Laminas\\',
        'League\\', 'Composer\\', 'Doctrine\\', 'phpDocumentor\\', 'PHPUnit\\',
        'Webmozart\\', 'Ramsey\\', 'Firebase\\', 'Google\\', 'Aws\\',
        'Carbon\\', 'Brick\\', 'Sabberworm\\', 'Pelago\\', 'Colinodell\\',
        'Fig\\', 'Zend\\',
    ];

    public static function isCollectionType(string $className): bool
    {
        return str_contains($className, 'Collection') && !str_contains($className, 'CollectionFactory');
    }

    public static function isCollectionFactoryType(string $className): bool
    {
        return str_contains($className, 'CollectionFactory');
    }

    public static function isRepository(string $className): bool
    {
        return str_contains($className, 'Repository');
    }

    public static function isResourceModel(string $className): bool
    {
        return str_contains($className, 'ResourceModel');
    }

    public static function isNonMagentoLibrary(string $className): bool
    {
        $normalizedClassName = ltrim($className, '\\');
        foreach (self::NON_MAGENTO_VENDORS as $vendor) {
            if (str_starts_with($normalizedClassName, $vendor)) {
                return true;
            }
        }
        return false;
    }

    public static function hasApiInterface(string $className): bool
    {
        if (!class_exists($className) && !interface_exists($className)) {
            return false;
        }

        $reflection = new ReflectionClass($className);
        foreach ($reflection->getInterfaceNames() as $interface) {
            if (str_contains($interface, 'Api')) {
                return true;
            }
        }

        return false;
    }

    public static function getApiInterface(string $className): string
    {
        try {
            $reflection = new ReflectionClass($className);
            foreach ($reflection->getInterfaceNames() as $interface) {
                if (str_contains($interface, 'Api')) {
                    return $interface;
                }
            }
        } catch (ReflectionException $e) {
            // Fallback below
        }
        return str_replace('Model\\', 'Api\\Data\\', $className) . 'Interface';
    }

    public static function matchesSuffix(string $className, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }
        return false;
    }

    public static function matchesSubstring(string $className, array $substrings): bool
    {
        foreach ($substrings as $substring) {
            if (str_contains($className, $substring)) {
                return true;
            }
        }
        return false;
    }
}
