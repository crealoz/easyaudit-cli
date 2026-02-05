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
            $cnt = count($this->beforePlugins);
            echo "  \033[33m!\033[0m Around plugins (should be before): \033[1;33m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'aroundToBeforePlugin',
                'name' => 'Before Plugin',
                'shortDescription' => 'This is a before plugin. The callable is invoked after '
                    . 'other code in the function.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the '
                    . 'callable is invoked after any other code in the function, it is a before '
                    . 'plugin. Doing so will prevent unnecessary propagation of the call through '
                    . 'the plugin chain.',
                'files' => $this->beforePlugins,
            ];
        }
        if (!empty($this->afterPlugins)) {
            $cnt = count($this->afterPlugins);
            echo "  \033[33m!\033[0m Around plugins (should be after): \033[1;33m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'aroundToAfterPlugin',
                'name' => 'After Plugin',
                'shortDescription' => 'This is an after plugin. The callable is invoked before '
                    . 'other code in the function.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the '
                    . 'callable is invoked before any other code in the function, it is an after '
                    . 'plugin. Doing so will prevent unnecessary propagation of the call through '
                    . 'the plugin chain.',
                'files' => $this->afterPlugins
            ];
        }
        if (!empty($this->overrides)) {
            $cnt = count($this->overrides);
            echo "  \033[31m✗\033[0m Overrides (should be preference): \033[1;31m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'overrideNotPlugin',
                'name' => 'Override, not a plugin',
                'shortDescription' => 'This is not a plugin, but an override. The callable is '
                    . 'never invoked.',
                'longDescription' => 'Around plugins are costly in terms of performance. If the '
                    . 'callable is never invoked, it is not a plugin, but an override. Consider '
                    . 'using a preference instead.',
                'files' => $this->overrides,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects around plugins and classifies them as before or after plugins '
            . 'based on the position of the callable invocation.';
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
     * For each function in the code, check if it is an around plugin with a regex "function around".
     *
     * @param string $code
     * @param string $file
     */
    private function isAroundPlugin(string $code, string $file): void
    {
        $pattern = '/\bfunction\s+(around\w+)\s*\(([^)]*)\)/';
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches as $match) {
            $functionName = $match[1][0];
            $lineNumber = substr_count(substr($code, 0, (int)$match[0][1]), "\n") + 1;
            $params = array_filter(array_map('trim', explode(',', $match[2][0])));

            $functionContent = Functions::getFunctionContent($code, $lineNumber);
            $callableName = $this->findCallableName($params, $functionContent['content']);

            $this->categorizePlugin($file, $functionName, $lineNumber, $functionContent, $callableName);
        }
    }

    /**
     * Find the callable parameter name from function parameters
     *
     * @param array $params Array of parameter strings
     * @param string $functionContent The function body content
     * @return string|null The callable parameter name or null if not found
     */
    private function findCallableName(array $params, string $functionContent): ?string
    {
        $paramNames = [];

        foreach ($params as $param) {
            $paramParts = preg_split('/\s+/', $param);
            $paramName = end($paramParts);
            $paramNames[] = $paramName;

            // Check for $proceed (Magento convention)
            if ($paramName === '$proceed') {
                return $paramName;
            }

            // Check for callable/Closure type hint
            $paramType = count($paramParts) > 1 ? $paramParts[0] : null;
            if ($paramType === 'callable' || $paramType === 'Closure' || $paramType === 'mixed') {
                return $paramName;
            }
        }

        // Fallback: check if any parameter is invoked as a function
        foreach ($paramNames as $paramName) {
            $callableLine = Functions::getOccuringLineInFunction($functionContent, $paramName . '();');
            if ($callableLine !== null) {
                return $paramName;
            }
        }

        return null;
    }

    /**
     * Categorize the plugin as before, after, or override
     *
     * @param string $file
     * @param string $functionName
     * @param int $lineNumber
     * @param array $functionContent
     * @param string|null $callableName
     */
    private function categorizePlugin(
        string $file,
        string $functionName,
        int $lineNumber,
        array $functionContent,
        ?string $callableName
    ): void {
        if ($callableName === null) {
            $msg = "No callable found in parameters or invocation. "
                . "So $functionName is an override, not a plugin.";
            $this->overrides[] = Formater::formatError(
                $file,
                $lineNumber,
                $msg,
                'error',
                $functionContent['endLine']
            );
            $this->foundCount++;
            return;
        }

        $innerContent = Functions::getFunctionInnerContent($functionContent['content']);
        $lines = explode("\n", $innerContent);

        if ($this->isAfterPlugin($lines, $callableName)) {
            $msg = "Plugin callable $callableName is invoked before other code "
                . "in the function. So $functionName is an after plugin.";
            $this->afterPlugins[] = Formater::formatError(
                $file,
                $lineNumber,
                $msg,
                'warning',
                $functionContent['endLine']
            );
            $this->foundCount++;
        } elseif ($this->isBeforePlugin($lines, $callableName)) {
            $msg = "Plugin callable $callableName is invoked after other code "
                . "in the function. So $functionName is a before plugin.";
            $this->beforePlugins[] = Formater::formatError(
                $file,
                $lineNumber,
                $msg,
                'warning',
                $functionContent['endLine']
            );
            $this->foundCount++;
        }
    }

    /**
     * Check if the callable is called before anything else in the function.
     *
     * @param  array  $lines
     * @param  string $callableName
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
     * @param  array  $lines
     * @param  string $callableName
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
        return 'Detects around plugins and classifies them as before or after plugins based '
            . 'on the position of the callable invocation. Around plugins are costly in terms '
            . 'of performance. If the callable is invoked before any other code in the function, '
            . 'it is an after plugin. If the callable is invoked after any other code in the '
            . 'function, it is a before plugin. Doing so will prevent unnecessary propagation '
            . 'of the call through the plugin chain. If the callable is never invoked, it is '
            . 'not a plugin, but an override. Consider using a preference instead.';
    }
}
