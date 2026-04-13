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
        return 'Detects deprecated escape method calls on $block or $this instead of $escaper in phtml '
            . 'templates.' . "\n"
            . 'Impact: Escape methods on $block or $this create a hard dependency on the Block instance '
            . 'being available and correctly typed. This makes escaping logic impossible to test '
            . 'independently and fragile when the rendering context changes.' . "\n"
            . 'Why change: Since Magento 2.3.5, $escaper is the supported service. Relying on the '
            . 'deprecated approach accumulates technical debt that will require migration before any '
            . 'major version upgrade that removes these methods from the Block class.' . "\n"
            . 'How to fix: Replace $block->escapeHtml(), $this->escapeHtml() (and escapeUrl, escapeJs, '
            . 'etc.) with $escaper->escapeHtml(). The $escaper variable is automatically available in '
            . 'all phtml templates since 2.3.5.';
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
            CliWriter::resultLine('Deprecated escaper usage ($block/$this instead of $escaper)', count($this->results), 'medium');
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
            $severity = $variable === 'this' ? 'high' : 'medium';

            $msg = "Use \$escaper->$method() instead of \$$variable->$method(). "
                . 'Escape methods on $' . $variable . ' are deprecated since Magento 2.3.5.';

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, $severity);
        }
    }
}
