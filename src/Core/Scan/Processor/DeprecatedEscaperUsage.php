<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\CliWriter;

/**
 * Detects deprecated escaper usage in phtml templates.
 *
 * Since Magento 2.3.5, escape methods (escapeHtml, escapeUrl, escapeJs, etc.)
 * should be called on $escaper instead of $block or $this.
 */
class DeprecatedEscaperUsage extends AbstractProcessor
{
    private const PATTERN = '/\$(block|this)->(escapeHtml|escapeUrl|escapeJs|escapeHtmlAttr|escapeCss|escapeQuote)\s*\(/';

    public function getIdentifier(): string
    {
        return 'useEscaper';
    }

    public function getFileType(): string
    {
        return 'phtml';
    }

    public function getName(): string
    {
        return 'Deprecated Escaper Usage';
    }

    public function getMessage(): string
    {
        return 'Detects deprecated escape method calls on $block or $this instead of $escaper.';
    }

    public function getLongDescription(): string
    {
        return 'Since Magento 2.3.5, escape methods (escapeHtml, escapeUrl, escapeJs, '
            . 'escapeHtmlAttr, escapeCss, escapeQuote) should be called on the $escaper '
            . 'variable instead of $block or $this in phtml templates. Using $block->escapeHtml() '
            . 'is deprecated, and using $this->escapeHtml() is even worse as it combines the '
            . 'deprecated escaper pattern with the $this anti-pattern. Migrate all escape calls '
            . 'to use $escaper->escapeHtml() and similar methods.';
    }

    public function process(array $files): void
    {
        if (empty($files['phtml'])) {
            return;
        }

        foreach ($files['phtml'] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $this->analyzeFile($file, $content);
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('Deprecated escaper usage ($block/$this instead of $escaper)', count($this->results), 'warning');
        }
    }

    private function analyzeFile(string $file, string $content): void
    {
        if (!preg_match_all(self::PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $i => $match) {
            $this->foundCount++;
            $offset = $match[1];
            $lineNumber = substr_count($content, "\n", 0, $offset) + 1;
            $variable = $matches[1][$i][0];
            $method = $matches[2][$i][0];
            $severity = $variable === 'this' ? 'error' : 'warning';

            $msg = "Use \$escaper->$method() instead of \$$variable->$method(). "
                . 'Escape methods on $' . $variable . ' are deprecated since Magento 2.3.5.';

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, $severity);
        }
    }
}
