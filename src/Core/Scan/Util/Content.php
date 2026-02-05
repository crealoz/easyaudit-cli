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

    public static function extractContent(string $fileContent, int $startLine, int $endLine): string
    {
        $lines = explode("\n", $fileContent);
        $extractedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        return implode("\n", $extractedLines);
    }
}
