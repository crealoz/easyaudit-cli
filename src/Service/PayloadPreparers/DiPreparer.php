<?php

namespace EasyAudit\Service\PayloadPreparers;

use EasyAudit\Exception\Fixer\CouldNotPreparePayloadException;
use EasyAudit\Service\CliWriter;

class DiPreparer extends AbstractPreparer
{
    /**
     * Group proxy findings by di.xml file, then by type (class).
     * Format: [diFile => [type => [['argument' => x, 'proxy' => y], ...]]]
     *
     * @param  array       $findings     Report findings
     * @param  array       $fixables     List of fixable ruleIds
     * @param  string|null $selectedRule Optional rule filter (only process this rule)
     * @return array Grouped by diFile -> type -> proxies
     */
    public function prepareFiles(array $findings, array $fixables, ?string $selectedRule = null): array
    {
        $byDiFile = [];

        foreach ($findings as $finding) {
            if (empty($finding['ruleId']) || !$this->canFix($finding['ruleId'], $fixables, $selectedRule) || !is_array($finding['files'])) {
                continue;
            }

            $this->processFiles($byDiFile, $finding['files']);
        }

        return $byDiFile;
    }

    protected function canFix($ruleId, array $fixables, ?string $selectedRule = null): bool
    {
        return $this->isSpecificRule($ruleId) && self::SPECIFIC_RULES[$ruleId] === self::class && $this->isRuleFixable($ruleId, $fixables, $selectedRule);
    }

    private function processFiles(&$byDiFile, array $files): void
    {
        foreach ($files as $file) {
            $metadata = $file['metadata'] ?? [];
            $diFile = $metadata['diFile'] ?? null;
            $type = $metadata['type'] ?? null;
            $argument = $metadata['argument'] ?? null;
            $proxy = $metadata['proxy'] ?? null;

            if (!$diFile || !$type || !$argument || !$proxy) {
                continue;
            }

            if (!isset($byDiFile[$diFile])) {
                $byDiFile[$diFile] = [];
            }

            if (!isset($byDiFile[$diFile][$type])) {
                $byDiFile[$diFile][$type] = [];
            }

            // Avoid duplicates
            $entry = ['argument' => $argument, 'proxy' => $proxy];
            if (!in_array($entry, $byDiFile[$diFile][$type], true)) {
                $byDiFile[$diFile][$type][] = $entry;
            }
        }
    }

    /**
     * Prepare payload for di.xml proxy fix.
     * Transforms proxy data into rules format expected by the API.
     *
     * @param  string $filePath Path to di.xml file
     * @param  array  $data     Proxies grouped by type: [type => [['argument' => x, 'proxy' => y], ...]]
     * @return array Payload with 'content' and 'rules' keys
     */
    public function preparePayload(string $filePath, array $data): array
    {
        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new CouldNotPreparePayloadException("Failed to read di.xml file: $filePath");
        }

        // Flatten proxies to array with explicit type field
        $proxyRules = [];
        foreach ($data as $type => $entries) {
            foreach ($entries as $entry) {
                $proxyRules[] = [
                    'type' => $type,
                    'argument' => $entry['argument'],
                    'proxy' => $entry['proxy'],
                ];
            }
        }

        return [
            'content' => $fileContent,
            'rules' => [
                'proxyConfiguration' => $proxyRules,
            ],
        ];
    }
}
