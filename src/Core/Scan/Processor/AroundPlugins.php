<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\ProcessorInterface;
use EasyAudit\Core\Scan\Util\Functions;

/**
 * Class AroundPlugins
 *
 * This processor is designed to handle "around" plugins in the scanning process.
 * It checks if the scanned code contains any around plugins and processes them accordingly.
 * If the callable is called before anything else, it will be flagged as after plugin.
 * If the callable is called after anything else, it will be flagged as before plugin.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class AroundPlugins implements ProcessorInterface
{
    private int $foundCount = 0;

    public function getIdentifier(): string
    {
        return 'around_plugins';
    }

    public function getFoundCount(): int
    {
        return $this->foundCount;
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function process(array $files): array
    {
        if (!isset($files['php']) || empty($files['php'])) {
            return [];
        }
        $results = [];
        foreach ($files['php'] as $file) {
            $code = file_get_contents($file);
            $resultsInFile = $this->isAroundPlugin($code);
            if (!empty($resultsInFile)) {
                $results[$file] = $resultsInFile;
            }
        }
        return $results;
    }

    /**
     * Fore each function in the code, check if it is an around plugin with a regex "function around".
     *
     * @param string $code
     * @return array with name of the function, line number, and whether it is before or after plugin
     */
    private function isAroundPlugin(string $code): array
    {
        $results = [];
        if (preg_match_all(
            '/\bfunction\s+(around\w+)\s*\(([^)]*)\)/',
            $code,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $match) {
                $functionName = $match[1][0];
                $lineNumber = substr_count(substr($code, 0, (int)$match[0][1]), "\n") + 1;
                // Check if any parameter is called as a function
                $params = array_filter(array_map('trim', explode(',', $match[2][0])));
                $paramNames = [];

                $functionContent = Functions::getFunctionContent($code, $lineNumber);
                foreach ($params as $param) {
                    $paramParts = preg_split('/\s+/', $param);
                    $paramName = end($paramParts);
                    $paramNames[] = $paramName;
                    if ($paramName === '$proceed') {
                        $callableName = $paramName;
                        break;
                    }
                    $paramType = count($paramParts) > 1 ? $paramParts[0] : null;
                    if ($paramType === 'callable' || $paramType === 'Closure' || $paramType === 'mixed') {
                        $callableName = $paramName;
                        break;
                    }
                    foreach ($paramNames as $paramName) {
                        $callableLine = Functions::getOccuringLineInFunction($functionContent, $paramName . '();');
                        if ($callableLine !== null) {
                            $callableName = $paramName;
                            break 2;
                        }
                    }
                }

                if (isset($callableName)) {
                    $functionInnerContent = Functions::getFunctionInnerContent($functionContent);
                    $lines = explode("\n", $functionInnerContent);
                    if ($this->isAfterPlugin($lines, $callableName)) {
                        // If the callable is called on the last line, it is an after plugin
                        $results[] = [
                            'function' => $functionName,
                            'line' => $lineNumber,
                            'type' => 'after-plugin',
                        ];
                        $this->foundCount++;
                    }  elseif ($this->isBeforePlugin($lines, $callableName)) {
                        // If the callable is called on the first line, it is a before plugin
                        $results[] = [
                            'function' => $functionName,
                            'line' => $lineNumber,
                            'type' => 'before',
                        ];
                        $this->foundCount++;
                    }
                } else {
                    // If the callable is not called, it is an override, not a plugin
                    $results[] = [
                        'function' => $functionName,
                        'line' => $lineNumber,
                        'type' => 'override-not-plugin',
                    ];
                    $this->foundCount++;
                }
            }
        }
        return $results;
    }

    /**
     * Check if the callable is called before anything else in the function.
     *
     * @param array $lines
     * @param string $callableName
     * @return bool
     */
    private function isAfterPlugin(array $lines, string $callableName): bool
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_contains($line, $callableName . '();')) {
                return true;
            }
            return false;
        }
    }

    /**
     * Check if the callable is called after anything else in the function.
     *
     * @param array $lines
     * @param string $callableName
     * @return bool
     */
    private function isBeforePlugin(array $lines, string $callableName): bool
    {
        $reversedLines = array_reverse($lines);
        foreach ($reversedLines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_contains($line, $callableName . '();')) {
                return true;
            }
            return false;
        }
    }
}