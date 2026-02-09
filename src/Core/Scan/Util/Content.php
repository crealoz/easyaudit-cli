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

    /**
     * Remove PHP comments (block, line, and hash) from content.
     */
    public static function removeComments(string $content): string
    {
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/\/\/.*$/m', '', $content);
        $content = preg_replace('/#.*$/m', '', $content);

        return $content;
    }

    /**
     * Find the actual line number of a needle near an approximate line.
     *
     * Searches a window around the approximate line in the original content.
     * Optionally normalizes whitespace for matching (useful for multi-line SQL).
     */
    public static function findApproximateLine(
        string $original,
        string $needle,
        int $approxLine,
        bool $normalizeWhitespace = false
    ): int {
        $lines = explode("\n", $original);
        $searchStart = max(0, $approxLine - 10);
        $searchEnd = min(count($lines), $approxLine + 10);

        for ($i = $searchStart; $i < $searchEnd; $i++) {
            if ($normalizeWhitespace) {
                $normalizedLine = preg_replace('/\s+/', ' ', $lines[$i]);
                $normalizedNeedle = preg_replace('/\s+/', ' ', $needle);
                if (str_contains($normalizedLine, trim($normalizedNeedle))) {
                    return $i + 1;
                }
            } else {
                if (str_contains($lines[$i], $needle)) {
                    return $i + 1;
                }
            }
        }

        return $approxLine;
    }
}
