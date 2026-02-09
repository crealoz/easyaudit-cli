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
        $lineNum = Content::getLineNumber($fileContent, $pluggingClass);
        $this->results[] = Formater::formatError($file, $lineNum, $msg, 'error');
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
        return 'Plugins are designed to modify the behavior of classes in other modules, '
            . 'promoting modularity and separation of concerns. Using plugins within the same '
            . 'module can create tight coupling between classes, making the codebase harder to '
            . 'maintain and understand. It is recommended to use preferences or direct class '
            . 'modifications for altering behavior within the same module, as these approaches '
            . 'are more straightforward and easier to manage.';
    }

    public function getName(): string
    {
        return 'Same Module Plugins';
    }
}
