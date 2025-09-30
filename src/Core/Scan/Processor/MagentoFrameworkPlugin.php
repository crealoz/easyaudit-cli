<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

class MagentoFrameworkPlugin extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'magentoFrameworkPlugin';
    }

    public function getFoundCount(): int
    {
        return 0;
    }

    public function process(array $files): void
    {
        $errors = [];
        if (empty($files['di'])) {
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
                $pluggedClassName = (string)$typeNode['name'];

                if (str_contains($pluggedClassName, 'Magento\Framework')) {
                    $this->foundCount++;
                    if (empty($fileContent)) {
                        $fileContent = file_get_contents($file);
                    }
                    $this->results[] = Formater::formatError(
                        $file,
                        Content::getLineNumber($fileContent, $pluggedClassName),
                        'Plugin for Magento core class ' . $pluggedClassName . ' is discouraged.',
                    );
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
        return 'Detects plugins that target Magento core classes, which is discouraged.';
    }

    public function getLongDescription(): string
    {
        return 'Plugins that target Magento core classes (those in the Magento\Framework namespace) are discouraged because they can lead to unexpected behavior and maintenance challenges. It is recommended to use other extension mechanisms, such as preferences or observers, when possible. On widely called classes, plugins can introduce performance overhead and complicate debugging.';
    }

    public function getName(): string
    {
        return 'Magento Core Class Plugins';
    }
}