<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
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
                'longDescription' => 'Using cacheable="false" is not recommended for blocks and '
                    . 'should be avoided. This attribute prevents the block from being cached, '
                    . 'which can significantly impact performance. Only use cacheable="false" '
                    . 'if the block must display dynamic, user-specific data (e.g., customer '
                    . 'information, cart contents, sales data). For most cases, consider '
                    . 'alternative approaches like using customer sections (private content) or '
                    . 'ESI (Edge Side Includes).',
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
        $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'note');
        return $fileContent;
    }

    public function getName(): string
    {
        return 'Cacheable Blocks';
    }

    public function getLongDescription(): string
    {
        return 'Blocks marked with cacheable="false" in layout XML files prevent Magento from '
            . 'caching those blocks, which can significantly degrade page load times and server '
            . 'performance. Full Page Cache (FPC) is one of Magento\'s most important '
            . 'performance features. When a block is not cacheable, the entire page often '
            . 'becomes uncacheable as well. For dynamic, user-specific content, use Magento\'s '
            . 'customer section (private content) mechanism or Edge Side Includes (ESI) '
            . 'instead. These approaches allow the page to remain cacheable while still '
            . 'providing personalized content through AJAX requests. Only use cacheable="false" '
            . 'when absolutely necessary, such as for checkout, cart, or customer '
            . 'account-specific blocks.';
    }
}
