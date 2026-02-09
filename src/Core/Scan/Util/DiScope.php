<?php

namespace EasyAudit\Core\Scan\Util;

class DiScope
{
    public const GLOBAL = 'global';
    public const FRONTEND = 'frontend';
    public const ADMINHTML = 'adminhtml';
    public const WEBAPI_REST = 'webapi_rest';
    public const WEBAPI_SOAP = 'webapi_soap';
    public const CRONTAB = 'crontab';
    public const GRAPHQL = 'graphql';

    private const AREA_DIRS = [
        '/etc/frontend/'     => self::FRONTEND,
        '/etc/adminhtml/'    => self::ADMINHTML,
        '/etc/webapi_rest/'  => self::WEBAPI_REST,
        '/etc/webapi_soap/'  => self::WEBAPI_SOAP,
        '/etc/crontab/'      => self::CRONTAB,
        '/etc/graphql/'      => self::GRAPHQL,
    ];

    private const ADMINHTML_PATTERNS = [
        '\\Block\\Adminhtml\\',
        '\\Controller\\Adminhtml\\',
        '\\Ui\\Component\\',
        '\\Adminhtml\\',
    ];

    private const FRONTEND_PATTERNS = [
        '\\ViewModel\\',
        '\\Controller\\Customer\\',
        '\\Controller\\Checkout\\',
        '\\Controller\\Catalog\\',
        '\\Controller\\Cart\\',
        '\\Frontend\\',
    ];

    /**
     * Returns the area scope for a di.xml file path.
     */
    public static function getScope(string $filePath): string
    {
        $normalized = str_replace('\\', '/', $filePath);

        foreach (self::AREA_DIRS as $dir => $area) {
            if (str_contains($normalized, $dir)) {
                return $area;
            }
        }

        return self::GLOBAL;
    }

    /**
     * True if the file is in the global (non-area) scope.
     */
    public static function isGlobal(string $filePath): bool
    {
        return self::getScope($filePath) === self::GLOBAL;
    }

    /**
     * Detect suggested area from a class/interface name.
     *
     * @return string|null 'frontend', 'adminhtml', or null if no specific area detected
     */
    public static function detectClassArea(string $className): ?string
    {
        foreach (self::ADMINHTML_PATTERNS as $pattern) {
            if (str_contains($className, $pattern)) {
                return self::ADMINHTML;
            }
        }

        // \Block\ without Adminhtml -> frontend
        if (str_contains($className, '\\Block\\') && !str_contains($className, '\\Adminhtml\\')) {
            return self::FRONTEND;
        }

        foreach (self::FRONTEND_PATTERNS as $pattern) {
            if (str_contains($className, $pattern)) {
                return self::FRONTEND;
            }
        }

        return null;
    }

    /**
     * Safe XML loading with libxml error suppression.
     */
    public static function loadXml(string $file): \SimpleXMLElement|false
    {
        return Xml::loadFile($file);
    }
}
