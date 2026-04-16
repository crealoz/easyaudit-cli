<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\ProcessorInterface;

abstract class AbstractProcessor implements ProcessorInterface
{
    protected array $results = [];

    protected int $foundCount = 0;

    public function getFoundCount(): int
    {
        return $this->foundCount;
    }

    public function getReport(): array
    {
        return [[
            'ruleId' => $this->getIdentifier(),
            'name' => $this->getName(),
            'shortDescription' => $this->getMessage(),
            'longDescription' => $this->getLongDescription(),
            'files' => $this->consolidateResults($this->results),
        ]];
    }

    /**
     * Consolidate results that share the same file and have consecutive lines.
     * Non-consecutive entries for the same file remain separate.
     */
    protected function consolidateResults(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $byFile = [];
        foreach ($results as $result) {
            $byFile[$result['file']][] = $result;
        }

        $consolidated = [];
        foreach ($byFile as $entries) {
            usort($entries, fn($a, $b) => $a['startLine'] <=> $b['startLine']);

            $current = $entries[0];
            if (isset($current['metadata'])) {
                $current['metadata'] = [$current['metadata']];
            }
            $messages = isset($current['message']) ? [$current['message']] : [];

            for ($i = 1, $count = count($entries); $i < $count; $i++) {
                $next = $entries[$i];
                if ($next['startLine'] <= $current['endLine'] + 1) {
                    $current['endLine'] = max($current['endLine'], $next['endLine']);
                    $current['severity'] = $this->higherSeverity($current['severity'], $next['severity']);
                    if (isset($next['metadata'])) {
                        $current['metadata'][] = $next['metadata'];
                    }
                    if (isset($next['message'])) {
                        $messages[] = $next['message'];
                    }
                } else {
                    $current = $this->mergeMessages($current, $messages);
                    $consolidated[] = $this->finalizeEntry($current);
                    $current = $next;
                    if (isset($current['metadata'])) {
                        $current['metadata'] = [$current['metadata']];
                    }
                    $messages = isset($current['message']) ? [$current['message']] : [];
                }
            }
            $current = $this->mergeMessages($current, $messages);
            $consolidated[] = $this->finalizeEntry($current);
        }

        return $consolidated;
    }

    /**
     * Unwrap single-element metadata arrays back to a plain object.
     */
    private function finalizeEntry(array $entry): array
    {
        if (isset($entry['metadata']) && count($entry['metadata']) === 1) {
            $entry['metadata'] = $entry['metadata'][0];
        }
        return $entry;
    }

    /**
     * Join multiple messages with newline when entries are merged.
     */
    private function mergeMessages(array $entry, array $messages): array
    {
        if (count($messages) > 1) {
            $entry['message'] = implode("\n", $messages);
        }
        return $entry;
    }

    private function higherSeverity(string $a, string $b): string
    {
        $order = ['note' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'error' => 4];
        return ($order[$b] ?? 0) > ($order[$a] ?? 0) ? $b : $a;
    }
}
