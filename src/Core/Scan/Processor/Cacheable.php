<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Modules;
use EasyAudit\Core\Scan\Util\Xml;

/**
 * Class Cacheable
 *
 * This processor detects blocks with cacheable="false" in layout XML files.
 * Using cacheable="false" negatively impacts performance and should be avoided
 * unless absolutely necessary (e.g., customer-specific data, sales data).
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class Cacheable extends AbstractProcessor
{
    /**
     * Block name patterns that are allowed to be non-cacheable
     * (customer, sales, gift-related blocks)
     */
    protected array $allowedAreas = ['sales', 'customer', 'gift', 'message'];

    public function getIdentifier(): string
    {
        return 'useCacheable';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getReport(): array
    {
        $report = [];
        if (!empty($this->results)) {
            $cnt = count($this->results);
            echo "  \033[31m✗\033[0m Cacheable=\"false\" blocks: \033[1;31m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'useCacheable',
                'name' => 'Use of cacheable="false"',
                'shortDescription' => 'Block with cacheable="false" found in layout XML.',
                'longDescription' => 'Detects cacheable="false" attribute on a block in layout XML.' . "\n"
                    . 'Impact: This single attribute disables Full Page Cache for the entire page, '
                    . 'not just this block. Every visitor triggers a full application stack hit on '
                    . 'every request.' . "\n"
                    . 'Why change: The effect is global and often goes unnoticed. At any meaningful '
                    . 'traffic level, this is one of the most damaging performance anti-patterns in '
                    . 'Magento 2.' . "\n"
                    . 'How to fix: Remove cacheable="false". Use the JS customer-data mechanism '
                    . '(private content sections) for user-specific content, or ESI blocks for '
                    . 'dynamic fragments.',
                'files' => $this->results,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects blocks with cacheable="false" in layout XML files, which can negatively impact performance.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        foreach ($files['xml'] as $file) {
            // Skip di.xml files (they're handled by other processors)
            if (str_ends_with(basename($file), 'di.xml')) {
                continue;
            }

            // Skip admin and email layout files (caching not applicable)
            if (str_contains($file, '/adminhtml/') || Modules::isEmailTemplate($file)) {
                continue;
            }

            $xml = Xml::loadFile($file);

            if ($xml === false) {
                continue;
            }

            $fileContent = '';
            $blocksNotCached = $xml->xpath('//block[@cacheable="false"]');

            if (count($blocksNotCached) > 0) {
                foreach ($blocksNotCached as $block) {
                    $fileContent = $this->checkBlock($block, $file, $fileContent);
                }
            }
        }
    }

    private function checkBlock($block, $file, $fileContent): string
    {

        $blockName = (string)$block->attributes()->name;

        // Skip if block name contains allowed areas
        foreach ($this->allowedAreas as $area) {
            if (stripos($blockName, $area) !== false) {
                return $fileContent;
            }
        }

        $this->foundCount++;
        if (empty($fileContent)) {
            $fileContent = file_get_contents($file);
        }

        $lineNumber = Content::getLineNumber($fileContent, $blockName);

        $msg = "Block '$blockName' uses cacheable=\"false\", which can impact "
            . "performance. Consider using customer sections or ESI instead.";
        $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'low');
        return $fileContent;
    }

    public function getName(): string
    {
        return 'Cacheable Blocks';
    }

    public function getLongDescription(): string
    {
        return 'Detects cacheable="false" in layout XML files.' . "\n"
            . 'Impact: A single cacheable="false" anywhere in the layout hierarchy disables Full Page '
            . 'Cache for the entire page, for every visitor. Every request then hits the full application '
            . 'stack and database. At any meaningful traffic level, this is one of the most damaging '
            . 'performance anti-patterns in Magento 2.' . "\n"
            . 'Why change: The effect is global, not localized to the block that declares it. It often '
            . 'goes unnoticed in development because the impact only manifests under production traffic '
            . 'levels.' . "\n"
            . 'How to fix: Remove cacheable="false" and use the JS customer-data mechanism (private '
            . 'content sections) for user-specific content, or ESI blocks for dynamic fragments. Only '
            . 'keep cacheable="false" for checkout/cart pages where it is truly unavoidable.';
    }
}
