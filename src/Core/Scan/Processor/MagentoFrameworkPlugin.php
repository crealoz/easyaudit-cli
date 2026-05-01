<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class MagentoFrameworkPlugin extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'magentoFrameworkPlugin';
    }

    public function process(array $files): void
    {
        if (empty($files['di'])) {
            return ;
        }
        foreach ($files['di'] as $file) {
            $xml = Xml::loadFile($file);

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

        if (!empty($this->results)) {
            echo "  \033[33m!\033[0m Plugins on Magento Framework classes: \033[1;33m" . count($this->results) . "\033[0m\n";
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
        return 'Flags plugins targeting classes in the Magento\Framework namespace.' . "\n"
            . 'Impact: Framework classes are instantiated on every request across all areas. An '
            . 'interceptor on any of them runs in the critical path unconditionally, increasing chain '
            . 'depth for the entire platform. Each additional framework plugin compounds this overhead.' . "\n"
            . 'Why change: Internal method signatures at this level can change between minor Magento '
            . 'releases. Conflicts with other plugins on the same framework class are unpredictable and '
            . 'extremely difficult to debug.' . "\n"
            . 'How to fix: Prefer event observers, which run only when dispatched. If interception is '
            . 'truly necessary, use a preference with careful version constraints. Document the reason '
            . 'for any framework-level plugin that remains.';
    }

    public function getName(): string
    {
        return 'Magento Core Class Plugins';
    }
}
