<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
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
            echo "  \033[31m✗\033[0m Duplicate preferences: \033[1;31m" . count($this->results) . "\033[0m\n";
            $report[] = [
                'ruleId' => 'duplicatePreferences',
                'name' => 'Duplicate Preferences',
                'shortDescription' => 'Multiple preferences found for the same interface/class.',
                'longDescription' => 'Multiple preferences found for the same interface/class. This can lead to unexpected behavior as only the last one will be applied, depending on module load sequence. Please remove duplicate preferences or check that sequence is done correctly in module declaration.',
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

        // First pass: collect all preferences
        foreach ($files['di'] as $file) {
            $previousUseErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($file);
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);

            if ($xml === false) {
                continue;
            }

            $preferences = $xml->xpath('//preference');
            foreach ($preferences as $preference) {
                $preferenceFor = (string)$preference['for'];
                $preferenceType = (string)$preference['type'];

                if (empty($preferenceFor) || empty($preferenceType)) {
                    continue;
                }

                if (!isset($this->existingPreferences[$preferenceFor])) {
                    $this->existingPreferences[$preferenceFor] = [];
                }

                $this->existingPreferences[$preferenceFor][] = [
                    'type' => $preferenceType,
                    'file' => $file
                ];
            }
        }

        // Second pass: report duplicates
        foreach ($this->existingPreferences as $interface => $preferences) {
            if (count($preferences) > 1) {
                $this->foundCount++;

                // Group by file for better reporting
                $fileList = [];
                foreach ($preferences as $pref) {
                    $file = $pref['file'];
                    $type = $pref['type'];

                    if (!isset($fileList[$file])) {
                        $fileContent = file_get_contents($file);
                        $lineNumber = Content::getLineNumber($fileContent, $interface);

                        $this->results[] = Formater::formatError(
                            $file,
                            $lineNumber,
                            "Multiple preferences found for '$interface'. This preference uses '$type'. Total preferences: " . count($preferences),
                            'error',
                            0,
                            [
                                'interface' => $interface,
                            ]
                        );
                        $fileList[$file] = true;
                    }
                }
            }
        }
    }

    public function getName(): string
    {
        return 'Multiple Preferences';
    }

    public function getLongDescription(): string
    {
        return 'Multiple preferences for the same interface or class can lead to unexpected behavior in Magento 2. ' .
               'When multiple modules define preferences for the same interface, only the last one (based on module load sequence) ' .
               'will be applied. This can cause hard-to-debug issues, especially when modules are loaded in a different order ' .
               'in different environments. It is recommended to use a single preference per interface, or to carefully manage ' .
               'module dependencies using the sequence tag in module.xml to ensure predictable behavior.';
    }
}
