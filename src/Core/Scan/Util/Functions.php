<?php

namespace EasyAudit\Core\Scan\Util;

class Functions
{
    /**
     * Returns the content of the function from the given code that starts at the specified line.
     * @todo Maybe too greedy, needs more testing
     *
     * @param string $code
     * @param int $startLine
     * @return array
     */
    public static function getFunctionContent(string $code, int $startLine): array
    {
        $lines = explode("\n", $code);
        $functionLines = [];
        $braceCount = 0;
        $inFunction = false;

        foreach ($lines as $lineNumber => $line) {
            if ($lineNumber + 1 < $startLine) {
                continue;
            }

            if (!$inFunction && preg_match('/^\s*(?:public|protected|private)?\s*function\s+\w+\s*\(/i', $line)) {
                $inFunction = true;
            }


            if ($inFunction) {
                $functionLines[] = $line;

                // Count opening and closing braces
                $braceCount += substr_count($line, '{');
                $braceCount -= substr_count($line, '}');

                // If brace count is zero, we've reached the end of the function
                if ($braceCount === 0 && str_contains($line, '}')) {
                    break;
                }
            }
        }

        if (!isset($lineNumber)) {
            throw new \RuntimeException("Function starting at line $startLine not found.");
        }

        return [
            'content' => implode("\n", $functionLines),
            'endLine' => $lineNumber + 1
        ];
    }

    public static function getFunctionInnerContent(string $functionContent): string
    {
        $lines = explode("\n", $functionContent);
        $innerLines = [];
        $braceCount = 0;
        $inInner = false;
        $firstBraceFound = false;

        foreach ($lines as $line) {

            if (preg_match('/^\s*(?:public|protected|private)?\s*function\s+\w+\s*\(/i', $line)) {
                /**
                 * We are at the function declaration line let's check if the opening brace is on the same line and
                 * if the closing brace is also on the same line. If so, we extract what is inside the braces and return it.
                 */
                if (preg_match('/\{(.*)\}/', $line, $m)) {
                    return trim($m[1]);
                }
                if (str_contains($line, '{')) {
                    $firstBraceFound = true;
                    $braceCount += substr_count($line, '{');
                }
                $inInner = true;
                continue; // Skip the function declaration line
            }

            if (str_contains($line, '{')) {
                $braceCount += substr_count($line, '{');
                if (!$firstBraceFound) {
                    $firstBraceFound = true;
                    continue; // Skip the line with the opening brace
                }
            }

            if (str_contains($line, '}')) {
                $braceCount -= substr_count($line, '}');
                if ($braceCount === 0) {
                    break; // Stop at the closing brace of the function
                }
            }

            if ($inInner) {
                $innerLines[] = $line;
            }
        }

        return implode("\n", $innerLines);
    }

    public static function getOccuringLineInFunction(string $functionContent, string $search): ?int
    {
        $lines = explode("\n", $functionContent);
        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, $search)) {
                return $lineNumber + 1;
            }
        }
        return null;
    }
}