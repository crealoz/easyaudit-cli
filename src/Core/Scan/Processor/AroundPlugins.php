<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\ProcessorInterface;
use EasyAudit\Core\Scan\Util\Formater;
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
class AroundPlugins extends AbstractProcessor
{

    private array $beforePlugins = [];
    private array $afterPlugins = [];
    private array $overrides = [];

    public function getIdentifier(): string
    {
        return 'around_plugins';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getReport(): array
    {
        $report = [];
        if (!empty($this->beforePlugins)) {
            echo 'Before plugins found: ' . count($this->beforePlugins) . PHP_EOL;
            $report[] = [
                'ruleId' => 'aroundToBeforePlugin',
                'name' => 'Before Plugin',
                'shortDescription' => 'This is a before plugin. The callable is invoked after other code in the function.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the callable is invoked after any other code in the function, it is a befor plugin. Doing so will prevent unnecessary propagation of the call through the plugin chain.',
                'files' => $this->beforePlugins,
            ];
        }
        if (!empty($this->afterPlugins)) {
            echo 'After plugins found: ' . count($this->afterPlugins) . PHP_EOL;
            $report[] = [
                'ruleId' => 'aroundToAfterPlugin',
                'name' => 'After Plugin',
                'shortDescription' => 'This is an after plugin. The callable is invoked before other code in the function.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the callable is invoked before any other code in the function, it is a after plugin. Doing so will prevent unnecessary propagation of the call through the plugin chain.',
                'files' => $this->afterPlugins
            ];
        }
        if (!empty($this->overrides)) {
            echo 'Overrides found: ' . count($this->overrides) . PHP_EOL;
            $report[] = [
                'ruleId' => 'overrideNotPlugin',
                'name' => 'Override, not a plugin',
                'shortDescription' => 'This is not a plugin, but an override. The callable is never invoked.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the callable is never invoked, it is not a plugin, but an override. Consider using a preference instead.',
                'files' => $this->overrides,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects around plugins and classifies them as before or after plugins based on the position of the callable invocation.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }
        foreach ($files['php'] as $file) {
            $code = file_get_contents($file);
            $this->isAroundPlugin($code, $file);
        }
    }

    /**
     * Fore each function in the code, check if it is an around plugin with a regex "function around".
     *
     * @param string $code
     * @param string $file
     */
    private function isAroundPlugin(string $code, string $file): void
    {
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
                        $callableLine = Functions::getOccuringLineInFunction($functionContent['content'], $paramName . '();');
                        if ($callableLine !== null) {
                            $callableName = $paramName;
                            break 2;
                        }
                    }
                }

                if (isset($callableName)) {
                    $functionInnerContent = Functions::getFunctionInnerContent($functionContent['content']);
                    $lines = explode("\n", $functionInnerContent);
                    if ($this->isAfterPlugin($lines, $callableName)) {
                        // If the callable is called on the last line, it is an after plugin
                        $this->afterPlugins[] = Formater::formatError($file, $lineNumber, "Plugin callable $callableName is invoked before other code in the function. So $functionName is an after plugin.", 'warning', $functionContent['endLine']);
                        $this->foundCount++;
                    }  elseif ($this->isBeforePlugin($lines, $callableName)) {
                        // If the callable is called on the first line, it is a before plugin
                        $this->beforePlugins[] = Formater::formatError($file, $lineNumber, "Plugin callable $callableName is invoked after other code in the function. So $functionName is a before plugin.", 'warning', $functionContent['endLine']);
                        $this->foundCount++;
                    }
                } else {
                    // If the callable is not called, it is an override, not a plugin
                    $this->overrides[] = Formater::formatError($file, $lineNumber, "No callable found in parameters or invocation. So $functionName is an override, not a plugin.", 'error', $functionContent['endLine']);
                    $this->foundCount++;
                }
            }
        }
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

    public function getName(): string
    {
        return 'Around Plugins';
    }

    public function getLongDescription(): string
    {
        return 'Detects around plugins and classifies them as before or after plugins based on the position of the callable invocation. Around plugins are costly in terms of performance. If the callable is invoked before any other code in the function, it is an after plugin. If the callable is invoked after any other code in the function, it is a before plugin. Doing so will prevent unnecessary propagation of the call through the plugin chain. If the callable is never invoked, it is not a plugin, but an override. Consider using a preference instead.';
    }
}