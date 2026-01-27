<?php

namespace EasyAudit\Service\PayloadPreparers;

use EasyAudit\Support\Paths;

class GeneralPreparer implements PreparerInterface
{
    /**
     * Group findings by file path instead of by ruleId.
     * Separates proxy rules (which modify di.xml) from regular file fixes.
     *
     * @param array $findings Report findings (grouped by ruleId)
     * @param array $fixables List of fixable ruleIds
     * @param string|null $selectedRule Optional rule filter (only process this rule)
     * @return array Files grouped by path with their issues (excludes proxy rules)
     */
    public function prepareFiles(array $findings, array $fixables, ?string $selectedRule = null): array
    {
        $byFile = [];

        foreach ($findings as $finding) {
            $ruleId = $finding['ruleId'] ?? '';
            if (!array_key_exists($ruleId, $fixables)) {
                continue;
            }

            // Filter by selected rule if specified
            if ($selectedRule !== null && $ruleId !== $selectedRule) {
                continue;
            }

            // Skip proxy rules - they are handled by DiPreparer
            if (array_key_exists($ruleId, self::SPECIFIC_RULES)) {
                continue;
            }

            foreach ($finding['files'] ?? [] as $file) {
                $filePath = Paths::getAbsolutePath($file['file']);

                if (!isset($byFile[$filePath])) {
                    $byFile[$filePath] = [
                        'issues' => [],
                    ];
                }

                $byFile[$filePath]['issues'][] = [
                    'ruleId' => $ruleId,
                    'metadata' => $file['metadata'] ?? [],
                ];
            }
        }

        return $byFile;
    }

    /**
     * Prepare payload for a single file in the API expected format.
     * Transforms issues array to rules object with metadata.
     *
     * @param string $filePath Path to the file
     * @param array $data File data with issues
     * @return array Payload with 'content' and 'rules' keys
     */
    public function preparePayload(string $filePath, array $data): array
    {
        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \RuntimeException("Failed to read file: $filePath");
        }

        // Transform issues array to rules object
        $rules = [];
        foreach ($data['issues'] as $issue) {
            $ruleId = $issue['ruleId'];
            $metadata = $issue['metadata'] ?? [];

            // Default: merge metadata for same rule
            if (!isset($rules[$ruleId])) {
                $rules[$ruleId] = $metadata;
            } else {
                $rules[$ruleId] = array_merge($rules[$ruleId], $metadata);
            }
        }

        return [
            'content' => $fileContent,
            'rules' => $rules,
        ];
    }
}
