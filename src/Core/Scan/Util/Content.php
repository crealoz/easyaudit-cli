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
     * @throws \InvalidArgumentException
     */
    public static function removeComments(string $content): string
    {
        $regexes = ['/\/\*.*?\*\//s', '/\/\/.*$/m', '/#.*$/m'];

        foreach ($regexes as $regex) {
            $content = preg_replace($regex, '', $content);
            if ($content === null) {
                throw new \InvalidArgumentException('preg_replace_error : ' . preg_last_error_msg() . ' on regex: ' . $regex);
            }
        }

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
        $normalizedNeedle = preg_replace('/\s+/', ' ', $needle);

        for ($i = $searchStart; $i < $searchEnd; $i++) {
            if ($normalizeWhitespace) {
                $normalizedLine = preg_replace('/\s+/', ' ', $lines[$i]);
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
