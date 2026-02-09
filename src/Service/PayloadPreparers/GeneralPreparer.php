<?php

namespace EasyAudit\Service\PayloadPreparers;

use EasyAudit\Exception\Fixer\CouldNotPreparePayloadException;
use EasyAudit\Support\Paths;

class GeneralPreparer extends AbstractPreparer
{
    /**
     * Group findings by file path instead of by ruleId.
     * Separates proxy rules (which modify di.xml) from regular file fixes.
     *
     * @param  array       $findings     Report findings (grouped by ruleId)
     * @param  array       $fixables     List of fixable ruleIds
     * @param  string|null $selectedRule Optional rule filter (only process this rule)
     * @return array Files grouped by path with their issues (excludes proxy rules)
     */
    public function prepareFiles(array $findings, array $fixables, ?string $selectedRule = null): array
    {
        $byFile = [];

        foreach ($findings as $finding) {
            if (empty($finding['ruleId']) || !$this->canFix($finding['ruleId'], $fixables, $selectedRule) || empty($finding['files'])) {
                continue;
            }
            $ruleId = $finding['ruleId'];

            foreach ($finding['files'] as $file) {
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

    protected function canFix($ruleId, array $fixables, ?string $selectedRule = null): bool
    {
        return !$this->isSpecificRule($ruleId) && $this->isRuleFixable($ruleId, $fixables, $selectedRule);
    }

    /**
     * Prepare payload for a single file in the API expected format.
     * Transforms issues array to rules object with metadata.
     *
     * @param  string $filePath Path to the file
     * @param  array  $data     File data with issues
     * @return array Payload with 'content' and 'rules' keys
     */
    public function preparePayload(string $filePath, array $data): array
    {
        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new CouldNotPreparePayloadException("Failed to read file: $filePath");
        }

        // Transform issues array to rules object
        $rules = [];
        foreach ($data['issues'] as $issue) {
            $ruleId = self::MAPPED_RULES[$issue['ruleId']] ?? $issue['ruleId'];
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
