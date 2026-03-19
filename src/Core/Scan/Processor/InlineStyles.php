<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Modules;
use EasyAudit\Service\CliWriter;

/**
 * Detects inline CSS in phtml and html templates.
 *
 * Inline styles (style="" attributes and <style> blocks) break Content Security Policy,
 * are harder to maintain, and prevent proper caching. Email and PDF templates are excluded
 * as they legitimately require inline CSS.
 */
class InlineStyles extends AbstractProcessor
{
    private const STYLE_TYPES = [
        'attribute' => [
            'pattern' => '/style\s*=\s*["\'][^"\']+["\']/i',
            'severity' => 'low',
            'ruleId' => 'magento.template.inline-style-attribute',
            'name' => 'Inline Style Attribute',
            'shortDescription' => 'Inline style attributes should be avoided',
            'longDescription' => 'Inline style attributes (style="...") in templates break Content '
                . 'Security Policy (CSP), are harder to maintain, and prevent proper caching of '
                . 'stylesheets. Move styles to CSS files or use CSS classes instead.',
        ],
        'block' => [
            'pattern' => '/<style\b[^>]*>.*?<\/style>/is',
            'severity' => 'medium',
            'ruleId' => 'magento.template.inline-style-block',
            'name' => 'Inline Style Block',
            'shortDescription' => 'Inline style blocks should be avoided',
            'longDescription' => 'Inline <style> blocks in templates break Content Security Policy '
                . '(CSP), are harder to maintain, and prevent proper caching. Move styles to '
                . 'dedicated CSS/LESS files and load them via layout XML.',
        ],
    ];

    private array $resultsByType = [];

    public function getIdentifier(): string
    {
        return 'inline_styles';
    }

    public function getFileType(): string
    {
        return 'phtml';
    }

    public function getName(): string
    {
        return 'Inline Styles';
    }

    public function getMessage(): string
    {
        return 'Detects inline CSS (style attributes and style blocks) in templates.';
    }

    public function getLongDescription(): string
    {
        return 'This processor detects inline CSS in phtml and html templates. Inline styles '
            . '(style="" attributes and <style> blocks) break Content Security Policy (CSP), '
            . 'are harder to maintain, and prevent proper stylesheet caching. Email and PDF '
            . 'templates are excluded as they legitimately require inline CSS.';
    }

    public function process(array $files): void
    {
        if (empty($files['phtml'])) {
            return;
        }

        $allTemplates = $files['phtml'];
        if (!empty($files['html'])) {
            $allTemplates = array_merge($allTemplates, $files['html']);
        }

        foreach ($allTemplates as $file) {
            if (Modules::isEmailTemplate($file) || Modules::isPdfTemplate($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false || $content === '') {
                continue;
            }

            $this->analyzeFile($file, $content);
        }

        $this->reportResults();
    }

    private function analyzeFile(string $file, string $content): void
    {
        foreach (self::STYLE_TYPES as $type => $config) {
            if (preg_match_all($config['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    $lineNumber = substr_count($content, "\n", 0, $offset) + 1;
                    $snippet = $this->truncateSnippet($match[0]);

                    $message = sprintf(
                        'Inline %s detected: "%s". Move styles to CSS files or use CSS classes.',
                        $type === 'attribute' ? 'style attribute' : 'style block',
                        $snippet
                    );

                    $error = Formater::formatError($file, $lineNumber, $message, $config['severity']);
                    $this->resultsByType[$type][] = $error;
                    $this->foundCount++;
                }
            }
        }
    }

    private function truncateSnippet(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (strlen($text) > 80) {
            return substr($text, 0, 77) . '...';
        }

        return $text;
    }

    private function reportResults(): void
    {
        foreach (self::STYLE_TYPES as $type => $config) {
            if (!empty($this->resultsByType[$type])) {
                CliWriter::resultLine(
                    'Inline style ' . ($type === 'attribute' ? 'attributes' : 'blocks'),
                    count($this->resultsByType[$type]),
                    $config['severity']
                );
            }
        }
    }

    public function getReport(): array
    {
        $report = [];

        foreach (self::STYLE_TYPES as $type => $config) {
            if (!empty($this->resultsByType[$type])) {
                $report[] = [
                    'ruleId' => $config['ruleId'],
                    'name' => $config['name'],
                    'shortDescription' => $config['shortDescription'],
                    'longDescription' => $config['longDescription'],
                    'files' => $this->resultsByType[$type],
                ];
            }
        }

        return $report;
    }
}
