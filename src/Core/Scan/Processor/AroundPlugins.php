<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Functions;
use EasyAudit\Core\Scan\Util\Interceptor;
use EasyAudit\Core\Scan\Util\PluginRegistry;

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
    private array $deepStacks = [];

    /** @var array<string, string[]> pluginFQCN => list of around method names found in scanned files */
    private array $aroundMethodsByPlugin = [];

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
                'longDescription' => 'Detects around plugins where all logic executes before '
                    . '$proceed, making them functionally before plugins.' . "\n"
                    . 'Impact: The around wrapper adds unnecessary call chain depth and overhead '
                    . 'for logic that only needs pre-processing.' . "\n"
                    . 'Why change: Before plugins are lighter, do not wrap the call chain, and make '
                    . 'the developer\'s intent explicit.' . "\n"
                    . 'How to fix: Convert to a before plugin. Move the logic to a '
                    . 'beforeMethodName() method and remove the $proceed call.',
                'files' => $this->consolidateResults($this->beforePlugins),
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
                'longDescription' => 'Detects around plugins where all logic follows $proceed, '
                    . 'making them functionally after plugins.' . "\n"
                    . 'Impact: The around wrapper adds unnecessary call chain depth and overhead '
                    . 'for logic that only needs post-processing.' . "\n"
                    . 'Why change: After plugins are lighter, receive the result directly, and make '
                    . 'the developer\'s intent explicit.' . "\n"
                    . 'How to fix: Convert to an after plugin. Move the logic to an '
                    . 'afterMethodName() method that receives the result as a parameter.',
                'files' => $this->consolidateResults($this->afterPlugins)
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
                'longDescription' => 'Detects around plugins that never call $proceed, completely '
                    . 'replacing the original method.' . "\n"
                    . 'Impact: This is an override disguised as a plugin, adding interceptor '
                    . 'overhead for no benefit and making the override harder to discover during '
                    . 'debugging.' . "\n"
                    . 'Why change: Preferences are the correct mechanism for full method replacement. '
                    . 'They are explicit, have no interceptor overhead, and are visible in di.xml '
                    . 'configuration.' . "\n"
                    . 'How to fix: Replace with a preference in di.xml.',
                'files' => $this->consolidateResults($this->overrides),
            ];
        }
        if (!empty($this->deepStacks)) {
            $cnt = count($this->deepStacks);
            echo "  \033[33m!\033[0m Deep around plugin stacks: \033[1;33m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'deepPluginStack',
                'name' => 'Deep Plugin Stack',
                'shortDescription' => 'Multiple around plugins target the same method, creating '
                    . 'a deep plugin call stack.',
                'longDescription' => 'Detects methods intercepted by multiple around plugins, '
                    . 'creating a deep nested call chain.' . "\n"
                    . 'Impact: Each around plugin wraps the next in the chain, multiplying overhead '
                    . 'and making execution flow extremely difficult to trace during debugging.' . "\n"
                    . 'Why change: Deep stacks amplify the performance cost of around plugins '
                    . 'exponentially and create fragile chains where one misbehaving plugin affects '
                    . 'all others.' . "\n"
                    . 'How to fix: Consolidate plugin logic into fewer plugins, or convert some to '
                    . 'before/after plugins which do not nest.',
                'files' => $this->consolidateResults($this->deepStacks),
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

        // Build plugin registry from di.xml files if available
        if (!empty($files['di'])) {
            PluginRegistry::build($files['di']);
        }

        foreach ($files['php'] as $file) {
            $code = file_get_contents($file);
            $this->isAroundPlugin($code, $file);
        }

        // Analyze plugin stack depth using generated interceptors
        if (Interceptor::isAvailable() && PluginRegistry::isBuilt()) {
            $this->analyzePluginStackDepth();
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

        // Track around methods by plugin class for stack depth analysis
        $pluginFqcn = Classes::extractClassName($code);
        $aroundMethods = [];

        foreach ($matches as $match) {
            $functionName = $match[1][0];
            $lineNumber = substr_count(substr($code, 0, $match[0][1]), "\n") + 1;
            $params = array_filter(array_map('trim', explode(',', $match[2][0])));

            $functionContent = Functions::getFunctionContent($code, $lineNumber);
            $callableName = $this->findCallableName($params);

            $this->categorizePlugin($file, $functionName, $lineNumber, $functionContent, $callableName);
            $aroundMethods[] = $functionName;
        }

        if (!empty($aroundMethods) && $pluginFqcn !== 'UnknownClass') {
            $this->aroundMethodsByPlugin[$pluginFqcn] = $aroundMethods;
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
                'high',
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
                'medium',
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
                'medium',
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

    /**
     * Analyze plugin stack depth using interceptor files and plugin registry.
     * For each target class with an interceptor, count how many sibling plugins
     * have around methods for the same original method.
     */
    private function analyzePluginStackDepth(): void
    {
        // Build a map: targetClass => [methodName => [{pluginClass, aroundMethod}]]
        $stackMap = [];

        foreach ($this->aroundMethodsByPlugin as $pluginFqcn => $aroundMethods) {
            $targetClass = PluginRegistry::getTargetClass($pluginFqcn);
            if ($targetClass === null) {
                continue;
            }

            foreach ($aroundMethods as $aroundMethod) {
                $originalMethod = self::deriveOriginalMethodName($aroundMethod);
                $stackMap[$targetClass][$originalMethod][] = $pluginFqcn;
            }
        }

        // Also check sibling plugins that weren't in the scanned PHP files
        foreach ($stackMap as $targetClass => $methods) {
            $interceptorPath = Interceptor::getInterceptorPath($targetClass);
            $interceptedMethods = $interceptorPath !== null
                ? Interceptor::getInterceptedMethods($interceptorPath)
                : [];

            $siblingPlugins = PluginRegistry::getPluginsForTarget($targetClass);

            foreach ($methods as $originalMethod => $knownPlugins) {
                // If interceptor exists, verify the method is actually intercepted
                if ($interceptorPath !== null && !in_array($originalMethod, $interceptedMethods, true)) {
                    continue;
                }

                // Check sibling plugins for around methods on the same original method
                $aroundMethodName = 'around' . ucfirst($originalMethod);
                foreach ($siblingPlugins as $sibling) {
                    $siblingClass = $sibling['class'];
                    if (in_array($siblingClass, $knownPlugins, true)) {
                        continue;
                    }

                    // Check if sibling has the around method in scanned files
                    if (isset($this->aroundMethodsByPlugin[$siblingClass])
                        && in_array($aroundMethodName, $this->aroundMethodsByPlugin[$siblingClass], true)
                    ) {
                        $stackMap[$targetClass][$originalMethod][] = $siblingClass;
                    }
                }

                // Report if stack depth >= 2
                $uniquePlugins = array_unique($stackMap[$targetClass][$originalMethod]);
                $depth = count($uniquePlugins);
                if ($depth >= 2) {
                    $pluginList = implode(', ', $uniquePlugins);
                    $msg = sprintf(
                        '%d around plugins on %s::%s() — plugins: %s',
                        $depth,
                        $targetClass,
                        $originalMethod,
                        $pluginList
                    );
                    // Report against the first di.xml file where the target is configured
                    $firstPlugin = $uniquePlugins[0];
                    $firstTarget = PluginRegistry::getTargetClass($firstPlugin);
                    $plugins = PluginRegistry::getPluginsForTarget($firstTarget ?? $targetClass);
                    $diFile = !empty($plugins) ? $plugins[0]['diFile'] : 'unknown';

                    $this->deepStacks[] = Formater::formatError($diFile, 1, $msg, 'high');
                    $this->foundCount++;
                }
            }
        }
    }

    /**
     * Derive the original method name from an around plugin method name.
     * e.g. aroundGetValue => getValue, aroundSave => save
     */
    public static function deriveOriginalMethodName(string $aroundName): string
    {
        $stripped = substr($aroundName, 6); // Remove 'around'
        return lcfirst($stripped);
    }

    public function getName(): string
    {
        return 'Around Plugins';
    }

    public function getLongDescription(): string
    {
        return 'Classifies around plugins as convertible to before or after based on $proceed position, '
            . 'and flags deep plugin stacks.' . "\n"
            . 'Impact: Around plugins wrap the full call chain and add overhead on every invocation. '
            . 'Stacked around plugins compound this: benchmarks show wall-time increases over 13,000% '
            . 'and memory overhead exceeding 62,000% compared to equivalent before/after implementations.' . "\n"
            . 'Why change: Most around plugins only need pre- or post-processing logic. Wrapping the '
            . 'entire method call when only one side is used wastes resources and makes the interceptor '
            . 'chain harder to debug.' . "\n"
            . 'How to fix: If all logic precedes $proceed, convert to a before plugin. If all logic '
            . 'follows $proceed, convert to an after plugin. If $proceed is never called, replace with '
            . 'a preference. For deep stacks, consolidate plugin logic or split across before/after.';
    }
}
