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
        $pattern = '/\bfunction\s+(around\w+)\s*\(([\s\S]*?)\)\s*[:{]/m';
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches as $match) {
            $functionName = $match[1][0];
            $lineNumber = substr_count(substr($code, 0, $match[0][1]), "\n") + 1;
            $params = array_filter(array_map('trim', explode(',', $match[2][0])));

            $functionContent = Functions::getFunctionContent($code, $lineNumber);
            $callableName = $this->findCallableName($params);

            $this->categorizePlugin($file, $functionName, $lineNumber, $functionContent, $callableName);
        }
    }

    /**
     * In Magento around plugins, the callable is always the second parameter.
     *
     * @param array $params Array of parameter strings
     * @return string|null The callable parameter name or null if fewer than 2 params
     */
    private function findCallableName(array $params): ?string
    {
        if (count($params) < 2) {
            return null;
        }
        $paramParts = preg_split('/\s+/', $params[1]);
        return end($paramParts);
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

        if ($this->isConditionalProceed($innerContent, $callableName)) {
            return;
        }

        $lines = explode("\n", $innerContent);

        if ($this->checkFirstCall($lines, $callableName)) {
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
        } elseif ($this->checkFirstCall(array_reverse($lines), $callableName)) {
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

    private function lineHasCallable(string $line, string $callableName): bool
    {
        return str_contains($line, $callableName . '(');
    }

    /**
     * Check if the callable is called before anything else in the function.
     *
     * @param  array  $lines
     * @param  string $callableName
     * @return bool
     */
    private function checkFirstCall(array $lines, string $callableName): bool
    {
        foreach ($lines as $line) {
            if ($this->isStructuralLine($line)) {
                continue;
            }
            return $this->lineHasCallable($line, $callableName);
        }
        return false;
    }

    /**
     * Check if a line is purely structural (empty, braces, try/catch/finally).
     * These lines don't represent meaningful code for plugin classification.
     */
    private function isStructuralLine(string $line): bool
    {
        $trimmed = trim($line);
        return $trimmed === ''
            || $trimmed === '}'
            || $trimmed === 'try {'
            || preg_match('/^\}\s*(catch|finally)\s*\(/', $trimmed)
            || preg_match('/^\}\s*(catch|finally)\s*\{/', $trimmed);
    }

    /**
     * Check if the callable is used conditionally (ternary, if/else, short-circuit).
     * Conditional usage is a legitimate around plugin pattern.
     */
    private function isConditionalProceed(string $innerContent, string $callableName): bool
    {
        $callableCall = $callableName . '(';
        $lines = explode("\n", $innerContent);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || !$this->lineHasCallable($line, $callableName)) {
                continue;
            }

            // Ternary: line contains '?' before the callable
            if (str_contains(strstr($line, $callableCall, true), '?')) {
                return true;
            }

            // Short-circuit: && $proceed() or || $proceed()
            if (
                str_contains($line, '&& ' . $callableCall) ||
                str_contains($line, '|| ' . $callableCall)
            ) {
                return true;
            }
        }

        // Conditional block: only count braces opened by conditional keywords
        $conditionalDepth = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/\b(if|elseif|else|switch|match)\b/', $trimmed)) {
                $conditionalDepth += substr_count($line, '{');
            }

            $conditionalDepth -= substr_count($line, '}');
            $conditionalDepth = max(0, $conditionalDepth);

            if ($conditionalDepth > 0 && $this->lineHasCallable($line, $callableName)) {
                return true;
            }
        }

        return false;
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
