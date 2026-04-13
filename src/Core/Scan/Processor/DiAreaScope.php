<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\DiScope;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\CliWriter;

/**
 * Class DiAreaScope
 *
 * Detects plugins and preferences in global di.xml that target area-specific classes.
 * If a plugin or preference targets a frontend-only or adminhtml-only class, it should
 * be declared in the corresponding area di.xml (etc/frontend/di.xml or etc/adminhtml/di.xml).
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class DiAreaScope extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'magento.di.global-area-scope';
    }

    public function getFileType(): string
    {
        return 'di';
    }

    public function getName(): string
    {
        return 'DI Area Scope Misplacement';
    }

    public function getMessage(): string
    {
        return 'Detects plugins/preferences in global di.xml that should be in area-specific di.xml.';
    }

    public function getLongDescription(): string
    {
        return 'Flags plugins and preferences in global etc/di.xml that target area-specific classes.' . "\n"
            . 'Impact: Global DI configuration is compiled and loaded for every request type. '
            . 'Area-specific interceptors declared globally inflate the DI graph and add objects to the '
            . 'instantiation surface of requests that have no use for them (e.g., frontend plugins '
            . 'loaded during cron or REST API calls).' . "\n"
            . 'Why change: Beyond wasted resources, area-scoped concerns buried in global configuration '
            . 'are harder to locate, reason about, and maintain. They also increase DI compilation time.' . "\n"
            . 'How to fix: Move the declaration to the corresponding area-specific file: '
            . 'etc/frontend/di.xml, etc/adminhtml/di.xml, or etc/webapi_rest/di.xml. The class behavior '
            . 'remains identical; only the loading scope changes.';
    }

    public function process(array $files): void
    {
        if (empty($files['di'])) {
            return;
        }

        foreach ($files['di'] as $file) {
            if (!DiScope::isGlobal($file)) {
                continue;
            }

            $xml = DiScope::loadXml($file);
            if ($xml === false) {
                continue;
            }

            $fileContent = file_get_contents($file);
            if ($fileContent === false) {
                continue;
            }

            $this->checkPlugins($xml, $file, $fileContent);
            $this->checkPreferences($xml, $file, $fileContent);
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('DI area scope misplacements', count($this->results), 'low');
        }
    }

    private function checkPlugins(\SimpleXMLElement $xml, string $file, string $fileContent): void
    {
        $types = $xml->xpath('//type[plugin]');
        if ($types === false) {
            return;
        }

        foreach ($types as $type) {
            $className = (string)$type['name'];
            if (empty($className)) {
                continue;
            }

            $area = DiScope::detectClassArea($className);
            if ($area === null) {
                continue;
            }

            $lineNumber = Content::getLineNumber($fileContent, $className);
            $msg = "Plugin on area-specific class '$className' is declared in global di.xml. "
                . "Consider moving to etc/{$area}/di.xml.";

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'low');
            $this->foundCount++;
        }
    }

    private function checkPreferences(\SimpleXMLElement $xml, string $file, string $fileContent): void
    {
        $preferences = $xml->xpath('//preference');
        if ($preferences === false) {
            return;
        }

        foreach ($preferences as $preference) {
            $forClass = (string)$preference['for'];
            $typeClass = (string)$preference['type'];

            if (empty($forClass)) {
                continue;
            }

            // Check both the interface and the implementation class
            $area = DiScope::detectClassArea($forClass) ?? DiScope::detectClassArea($typeClass);
            if ($area === null) {
                continue;
            }

            $lineNumber = Content::getLineNumber($fileContent, $forClass);
            $msg = "Preference for area-specific class '$forClass' is declared in global di.xml. "
                . "Consider moving to etc/{$area}/di.xml.";

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'low');
            $this->foundCount++;
        }
    }
}
