<?php

namespace EasyAudit\Core\Scan\Util;

class Xml
{
    /**
     * Load an XML file with safe libxml error handling.
     * Returns false on parse failure without triggering PHP warnings.
     */
    public static function loadFile(string $file): \SimpleXMLElement|false
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return $xml;
    }
}
