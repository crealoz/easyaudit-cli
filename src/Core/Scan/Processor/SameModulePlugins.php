<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

class SameModulePlugins extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'same-module-plugins';
    }

    public function process(array $files): void
    {
        if (!isset($files['di']) || empty($files['di'])) {
            return ;
        }
        foreach ($files['di'] as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            $fileContent = '';
            $typeNodes = $xml->xpath('//type[plugin]');
            foreach ($typeNodes as $typeNode) {
                $pluginNodes = $typeNode->xpath('plugin');
                $pluggedClassName = (string)$typeNode['name'];

                foreach ($pluginNodes as $pluginNode) {
                    $pluggingClassName = (string)$pluginNode['type'];
                    $pluginDisabled = (string)$pluginNode['disabled'] ?? 'false';
                    if ($pluginDisabled === 'true') {
                        continue;
                    }
                    if (empty($pluggingClassName) || empty($pluggedClassName)) {
                        continue;
                    }
                    $pluggingClassParts = explode('\\', $pluggingClassName);
                    $pluggedInClassParts = explode('\\', $pluggedClassName);
                    if (isset($pluggingClassParts[1]) && isset($pluggedInClassParts[1]) &&
                        $pluggingClassParts[0] === $pluggedInClassParts[0] &&
                        $pluggingClassParts[1] === $pluggedInClassParts[1]
                    ) {
                        $this->foundCount++;
                        if (empty($fileContent)) {
                            $fileContent = file_get_contents($file);
                        }
                        $this->results[] = Formater::formatError($file, Content::getLineNumber($fileContent, $pluggingClassName), $pluggingClassName . ' plugs ' . $pluggedClassName . ' within the same module.', 'error');
                    }
                }
            }
        }
    }

    public function getFileType(): string
    {
        return 'di';
    }

    public function getMessage(): string
    {
        return 'Plugins should not be used to modify the behavior of classes within the same module. This can lead to maintenance challenges and unexpected behaviors. Consider using preferences or direct class modifications instead.';
    }

    public function getLongDescription(): string
    {
        return 'Plugins are designed to modify the behavior of classes in other modules, promoting modularity and separation of concerns. Using plugins within the same module can create tight coupling between classes, making the codebase harder to maintain and understand. It is recommended to use preferences or direct class modifications for altering behavior within the same module, as these approaches are more straightforward and easier to manage.';
    }

    public function getName(): string
    {
        return 'Same Module Plugins';
    }
}