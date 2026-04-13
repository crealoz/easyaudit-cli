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
                'longDescription' => 'Detects multiple di.xml preferences targeting the same '
                    . 'interface or class.' . "\n"
                    . 'Impact: Only one preference can be active at runtime, and which one wins '
                    . 'depends on module load order. This creates non-deterministic behavior that '
                    . 'is hard to debug.' . "\n"
                    . 'Why change: Adding, removing, or reordering any module can silently change '
                    . 'the active implementation without any visible configuration change.' . "\n"
                    . 'How to fix: Remove duplicate preferences, or ensure correct module ordering '
                    . 'via the <sequence> tag in module.xml.',
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
                'high',
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
        return 'Flags multiple di.xml preferences declared for the same interface or class across '
            . 'modules.' . "\n"
            . 'Impact: Only one preference can be active at runtime. Which one wins depends on module '
            . 'load order, which is not guaranteed to be stable across environments. The result is '
            . 'non-deterministic behavior that is hard to reproduce and debug.' . "\n"
            . 'Why change: These conflicts are difficult to detect in development and easy to miss in '
            . 'code review. Adding, removing, or reordering any module in the dependency graph can '
            . 'silently change the active implementation.' . "\n"
            . 'How to fix: Use plugins for cross-module behavior modification. Reserve preferences for '
            . 'single, well-coordinated overrides where exclusivity is intentional. If multiple '
            . 'preferences are necessary, manage module load order explicitly via the <sequence> tag in '
            . 'module.xml.';
    }
}
