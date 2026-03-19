<?php

namespace EasyAudit\Core\Scan\Util;

use EasyAudit\Core\Scan\Scanner;

/**
 * Reads Magento generated Interceptor files to determine which methods are interceptable.
 */
class Interceptor
{
    /**
     * Check if generated interceptors are available for the current scan.
     */
    public static function isAvailable(): bool
    {
        return Scanner::getGeneratedPath() !== null;
    }

    /**
     * Convert a FQCN to its Interceptor file path and return it if the file exists.
     *
     * e.g. Vendor\Module\Model\Toto => generated/code/Vendor/Module/Model/Toto/Interceptor.php
     */
    public static function getInterceptorPath(string $className): ?string
    {
        $generatedPath = Scanner::getGeneratedPath();
        if ($generatedPath === null) {
            return null;
        }

        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, ltrim($className, '\\'));
        $interceptorFile = $generatedPath . DIRECTORY_SEPARATOR . $classPath
            . DIRECTORY_SEPARATOR . 'Interceptor.php';

        if (file_exists($interceptorFile)) {
            return $interceptorFile;
        }

        return null;
    }

    /**
     * Extract method names from an Interceptor file that use ___callPlugins.
     *
     * @return string[] Method names that are intercepted
     */
    public static function getInterceptedMethods(string $interceptorPath): array
    {
        $content = @file_get_contents($interceptorPath);
        if ($content === false) {
            return [];
        }

        $methods = [];
        // Interceptor methods call ___callPlugins('methodName', ...)
        if (preg_match_all('/___callPlugins\s*\(\s*[\'"](\w+)[\'"]/', $content, $matches)) {
            $methods = array_unique($matches[1]);
        }

        return $methods;
    }
}
