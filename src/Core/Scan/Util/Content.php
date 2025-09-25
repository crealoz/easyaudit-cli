<?php

namespace EasyAudit\Core\Scan\Util;

class Content
{
    public static function getLineNumber(string $fileContent, string $searchString): int
    {
        $lines = explode("\n", $fileContent);
        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, $searchString)) {
                return $lineNumber + 1; // Line numbers start at 1
            }
        }
        return -1; // Not found
    }
}