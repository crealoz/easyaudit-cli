<?php

namespace EasyAudit\Core\Scan\Processor;

class MagentoFrameworkPlugin implements \EasyAudit\Core\Scan\ProcessorInterface
{
    public function getIdentifier(): string
    {
        return 'magento-framework-plugins';
    }

    public function getFoundCount(): int
    {
        return 0;
    }

    public function process(array $files): array
    {
        $errors = [];
        if (empty($files['di'])) {
            return $errors;
        }
        foreach ($files['di'] as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            $typeNodes = $xml->xpath('//type[plugin]');
            foreach ($typeNodes as $typeNode) {
                $pluggedClassName = (string)$typeNode['name'];

                if (str_contains($pluggedClassName, 'Magento\Framework')) {
                    $errors[] = [
                        'file' => $file,
                        'message' => "Class '$pluggedClassName' is a core Magento class and should not be plugged.",
                    ];
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