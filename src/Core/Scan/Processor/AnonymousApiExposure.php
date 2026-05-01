<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class AnonymousApiExposure extends AbstractProcessor
{
    private const JUSTIFICATION_WINDOW_LINES = 3;

    public function getIdentifier(): string
    {
        return 'anonymousApiExposure';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'Undocumented Anonymous Web API Route';
    }

    public function getMessage(): string
    {
        return 'Flags webapi.xml routes exposed to anonymous callers without a justifying XML comment.';
    }

    public function getLongDescription(): string
    {
        return 'Flags webapi.xml routes declared with resource ref="anonymous" that do not have an XML '
            . 'comment within three lines immediately above the <route> tag.' . "\n"
            . 'Impact: Anonymous routes are reachable without authentication. Most REST endpoints should '
            . 'require a logged-in customer or admin. Anonymous access is occasionally correct '
            . '(checkout-as-guest, storefront catalog, CSRF-protected token exchanges) but is rarely the '
            . 'right default and is easy to forget when prototyping.' . "\n"
            . 'Why change: An undocumented anonymous endpoint is indistinguishable from a forgotten '
            . 'debugging route or a copy/paste mistake. Reviewers have no signal that the exposure is '
            . 'intentional.' . "\n"
            . 'How to fix: If the route genuinely needs anonymous access, add an XML comment directly '
            . 'above it explaining why (e.g. "storefront catalog — public by design"). If anonymous '
            . 'exposure is a mistake, change the resource reference to the correct ACL id or self.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        foreach ($files['xml'] as $file) {
            if (basename($file) !== 'webapi.xml') {
                continue;
            }
            $this->processWebapiFile($file);
        }

        if (!empty($this->results)) {
            echo "  \033[33m!\033[0m Undocumented anonymous API routes: \033[1;33m"
                . count($this->results) . "\033[0m\n";
        }
    }

    private function processWebapiFile(string $file): void
    {
        $xml = Xml::loadFile($file);
        if ($xml === false) {
            return;
        }

        $fileContent = file_get_contents($file);
        $lines = explode("\n", $fileContent);

        foreach ($xml->xpath('//route') as $route) {
            if (!$this->isAnonymous($route)) {
                continue;
            }

            $url = (string)$route['url'];
            $method = strtoupper((string)$route['method']);

            $lineNumber = $this->findRouteLine($lines, $url, $method);
            if ($lineNumber === 0) {
                continue;
            }

            if ($this->hasJustificationComment($lines, $lineNumber)) {
                continue;
            }

            $this->foundCount++;
            $this->results[] = Formater::formatError(
                $file,
                $lineNumber,
                sprintf(
                    'Route %s %s is exposed to anonymous callers without a justifying comment. '
                    . 'Add an XML comment directly above explaining why anonymous access is intentional, '
                    . 'or change the resource reference.',
                    $method,
                    $url
                ),
                'warning',
                0,
                [
                    'httpMethod' => $method,
                    'url' => $url,
                ]
            );
        }
    }

    private function isAnonymous(\SimpleXMLElement $route): bool
    {
        if (!isset($route->resources)) {
            return false;
        }

        foreach ($route->resources->resource as $resource) {
            if ((string)$resource['ref'] === 'anonymous') {
                return true;
            }
        }

        return false;
    }

    private function findRouteLine(array $lines, string $url, string $method): int
    {
        $needle = 'url="' . $url . '"';
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (str_contains((string)$lines[$i], $needle) && stripos((string)$lines[$i], $method) !== false) {
                return $i + 1;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            if (str_contains((string)$lines[$i], $needle)) {
                return $i + 1;
            }
        }

        return 0;
    }

    private function hasJustificationComment(array $lines, int $routeLine): bool
    {
        $startIndex = max(0, $routeLine - 1 - self::JUSTIFICATION_WINDOW_LINES);
        $endIndex = $routeLine - 1;

        $window = array_slice($lines, $startIndex, $endIndex - $startIndex);
        $block = implode("\n", $window);

        // Any XML comment in the three-line window counts as a justification.
        return preg_match('/<!--.+?-->/s', $block) === 1
            || str_contains($block, '<!--');
    }
}
