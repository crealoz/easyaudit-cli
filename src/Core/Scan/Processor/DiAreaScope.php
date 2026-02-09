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
        return 'Plugins and preferences declared in the global etc/di.xml are loaded for every '
            . 'area (frontend, adminhtml, cron, REST API, etc.). When they target area-specific '
            . 'classes (e.g., frontend blocks or admin controllers), they add unnecessary overhead '
            . 'in areas where they are never used. Move them to the appropriate area di.xml '
            . '(etc/frontend/di.xml or etc/adminhtml/di.xml) to reduce the DI compilation '
            . 'footprint and improve performance.';
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
            CliWriter::resultLine('DI area scope misplacements', count($this->results), 'note');
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

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'note');
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

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'note');
            $this->foundCount++;
        }
    }
}
