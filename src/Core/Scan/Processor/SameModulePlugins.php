<?php

namespace EasyAudit\Core\Scan\Processor;

class SameModulePlugins implements \EasyAudit\Core\Scan\ProcessorInterface
{
    public function getIdentifier(): string
    {
        return 'same-module-plugins';
    }

    public function getFoundCount(): int
    {
        return 0;
    }

    public function process(array $files): array
    {
        $errors = [];
        if (!isset($files['di']) || empty($files['di'])) {
            return $errors;
        }
        foreach ($files['di'] as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
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
                        $errors[] = [
                            'file' => $file,
                            'message' => "Class '$pluggingClassName' is plugging $pluggedClassName that is in the same module.",
                        ];
                    }
                }
            }
        }

        return $errors;
    }

    public function getFileType(): string
    {
        return 'di';
    }
}