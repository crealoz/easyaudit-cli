<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\DiScope;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class Preferences
 *
 * This processor detects multiple preferences for the same interface/class in di.xml files.
 * Multiple preferences can lead to unexpected behavior as only the last one will be applied,
 * depending on module load sequence.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class Preferences extends AbstractProcessor
{
    private array $existingPreferences = [];

    public function getIdentifier(): string
    {
        return 'duplicatePreferences';
    }

    public function getFileType(): string
    {
        return 'di';
    }

    public function getReport(): array
    {
        $report = [];
        if (!empty($this->results)) {
            $cnt = count($this->results);
            echo "  \033[31m✗\033[0m Duplicate preferences: \033[1;31m" . $cnt . "\033[0m\n";
            $report[] = [
                'ruleId' => 'duplicatePreferences',
                'name' => 'Duplicate Preferences',
                'shortDescription' => 'Multiple preferences found for the same interface/class.',
                'longDescription' => 'Multiple preferences found for the same interface/class. '
                    . 'This can lead to unexpected behavior as only the last one will be '
                    . 'applied, depending on module load sequence. Please remove duplicate '
                    . 'preferences or check that sequence is done correctly in module declaration.',
                'files' => $this->results,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects multiple preferences for the same interface/class in di.xml files across the entire codebase.';
    }

    public function process(array $files): void
    {
        $this->existingPreferences = [];

        if (empty($files['di'])) {
            return;
        }

        $this->collectPreferences($files['di']);
        $this->reportDuplicates();
    }

    /**
     * First pass: collect all preferences from di.xml files
     */
    private function collectPreferences(array $diFiles): void
    {
        foreach ($diFiles as $file) {
            $xml = DiScope::loadXml($file);
            if ($xml === false) {
                continue;
            }

            foreach ($xml->xpath('//preference') as $preference) {
                $this->addPreference($file, $preference);
            }
        }
    }

    /**
     * Add a preference to the collection
     */
    private function addPreference(string $file, \SimpleXMLElement $preference): void
    {
        $preferenceFor = (string)$preference['for'];
        $preferenceType = (string)$preference['type'];

        if (empty($preferenceFor) || empty($preferenceType)) {
            return;
        }

        $scope = DiScope::getScope($file);

        if (!isset($this->existingPreferences[$preferenceFor])) {
            $this->existingPreferences[$preferenceFor] = [];
        }

        $this->existingPreferences[$preferenceFor][] = [
            'type' => $preferenceType,
            'file' => $file,
            'scope' => $scope,
        ];
    }

    /**
     * Second pass: report interfaces with multiple preferences in the same scope
     */
    private function reportDuplicates(): void
    {
        foreach ($this->existingPreferences as $interface => $preferences) {
            // Group preferences by scope
            $byScope = [];
            foreach ($preferences as $pref) {
                $byScope[$pref['scope']][] = $pref;
            }

            // Only flag scopes with more than one preference
            foreach ($byScope as $scopePreferences) {
                if (count($scopePreferences) <= 1) {
                    continue;
                }
                $this->reportDuplicatePreference($interface, $scopePreferences);
            }
        }
    }

    /**
     * Report a single duplicate preference, one result per unique file
     */
    private function reportDuplicatePreference(string $interface, array $preferences): void
    {
        $this->foundCount++;
        $totalCount = count($preferences);
        $reportedFiles = [];

        foreach ($preferences as $pref) {
            $file = $pref['file'];

            // Avoid reporting the same file twice for this interface
            if (isset($reportedFiles[$file])) {
                continue;
            }
            $reportedFiles[$file] = true;

            $fileContent = file_get_contents($file);
            $lineNumber = Content::getLineNumber($fileContent, $interface);

            $msg = "Multiple preferences found for '$interface'. This preference "
                . "uses '{$pref['type']}'. Total preferences: $totalCount";

            $this->results[] = Formater::formatError(
                $file,
                $lineNumber,
                $msg,
                'error',
                0,
                ['interface' => $interface]
            );
        }
    }

    public function getName(): string
    {
        return 'Multiple Preferences';
    }

    public function getLongDescription(): string
    {
        return 'Multiple preferences for the same interface or class can lead to unexpected '
            . 'behavior in Magento 2. When multiple modules define preferences for the same '
            . 'interface, only the last one (based on module load sequence) will be applied. '
            . 'This can cause hard-to-debug issues, especially when modules are loaded in a '
            . 'different order in different environments. It is recommended to use a single '
            . 'preference per interface, or to carefully manage module dependencies using the '
            . 'sequence tag in module.xml to ensure predictable behavior.';
    }
}
