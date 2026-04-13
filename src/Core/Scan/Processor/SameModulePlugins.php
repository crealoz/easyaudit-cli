<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\DiScope;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Modules;
use EasyAudit\Service\CliWriter;
use SimpleXMLElement;

class SameModulePlugins extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'sameModulePlugin';
    }

    public function process(array $files): void
    {
        if (empty($files['di'])) {
            return;
        }

        foreach ($files['di'] as $file) {
            $xml = DiScope::loadXml($file);
            if ($xml === false) {
                continue;
            }
            $this->analyzePlugins($file, $xml);
        }

        $this->printResults();
    }

    /**
     * Analyze all plugins in a di.xml file.
     */
    private function analyzePlugins(string $file, SimpleXMLElement $xml): void
    {
        $fileContent = '';
        $typeNodes = $xml->xpath('//type[plugin]');

        foreach ($typeNodes as $typeNode) {
            $pluggedClassName = (string)$typeNode['name'];
            $pluginNodes = $typeNode->xpath('plugin');

            foreach ($pluginNodes as $pluginNode) {
                $pluggingClassName = (string)$pluginNode['type'];

                if (!$this->isValidPlugin($pluginNode, $pluggingClassName, $pluggedClassName)) {
                    continue;
                }

                if (Modules::isSameModule($pluggingClassName, $pluggedClassName)) {
                    $this->recordSameModulePlugin($file, $fileContent, $pluggingClassName, $pluggedClassName);
                }
            }
        }
    }

    /**
     * Check if plugin definition is valid and not disabled.
     */
    private function isValidPlugin(SimpleXMLElement $pluginNode, string $pluggingClass, string $pluggedClass): bool
    {
        $pluginDisabled = (string)($pluginNode['disabled'] ?? 'false');

        if ($pluginDisabled === 'true') {
            return false;
        }

        return !empty($pluggingClass) && !empty($pluggedClass);
    }

    /**
     * Record a same-module plugin violation.
     */
    private function recordSameModulePlugin(
        string $file,
        string &$fileContent,
        string $pluggingClass,
        string $pluggedClass
    ): void {
        $this->foundCount++;

        if (empty($fileContent)) {
            $fileContent = file_get_contents($file);
        }

        $msg = $pluggingClass . ' plugs ' . $pluggedClass . ' within the same module.';
        // Search for the type declaration with name attribute to avoid matching earlier references
        $lineNum = Content::getLineNumber($fileContent, 'name="' . $pluggedClass . '"');
        $this->results[] = Formater::formatError($file, $lineNum, $msg, 'high');
    }

    /**
     * Print results summary if any violations found.
     */
    private function printResults(): void
    {
        if (!empty($this->results)) {
            CliWriter::resultLine('Same module plugins', count($this->results));
        }
    }

    public function getFileType(): string
    {
        return 'di';
    }

    public function getMessage(): string
    {
        return 'Plugins should not be used to modify the behavior of classes within the same '
            . 'module. This can lead to maintenance challenges and unexpected behaviors. '
            . 'Consider using preferences or direct class modifications instead.';
    }

    public function getLongDescription(): string
    {
        return 'Flags plugins that intercept a class belonging to the same module.' . "\n"
            . 'Impact: A plugin adds an interceptor layer with its own instantiation and dispatch '
            . 'overhead. When used within the same module, that overhead is paid with no architectural '
            . 'benefit since there is no cross-module extensibility boundary to respect.' . "\n"
            . 'Why change: The result is added complexity, runtime cost, and indirection for something '
            . 'that a direct class modification or a preference would handle more clearly and '
            . 'efficiently.' . "\n"
            . 'How to fix: Replace the plugin with a preference if substitution is needed, or modify '
            . 'the target class directly since both are in the same module and under the same ownership.';
    }

    public function getName(): string
    {
        return 'Same Module Plugins';
    }
}
