<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Graph;
use EasyAudit\Core\Scan\Util\Xml;

class IndexerCircular extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'indexerCircular';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'Indexer Circular Dependency';
    }

    public function getMessage(): string
    {
        return 'Detects circular dependencies declared between indexers across indexer.xml files.';
    }

    public function getLongDescription(): string
    {
        return 'Flags cycles in the indexer dependency graph declared via <dependencies> in '
            . 'indexer.xml.' . "\n"
            . 'Impact: A cycle causes the indexer framework to either fail outright or enter an '
            . 'unspecified order. When two indexers each require the other to run first, neither can '
            . 'be safely materialized, and the reindex command may stall, repeat, or error.' . "\n"
            . 'Why change: Cross-module indexer dependencies are easy to introduce accidentally when '
            . 'two modules each declare they depend on the other — often because each maintainer '
            . 'assumed theirs ran first.' . "\n"
            . 'How to fix: Break the cycle by removing the weaker dependency, or introduce a third '
            . 'indexer that both depend on. Review the business meaning: if indexer A truly needs B, '
            . 'and B truly needs A, then the data model likely needs restructuring, not the '
            . 'indexer xml.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        $adjacency = [];
        $declarationFile = [];

        foreach ($files['xml'] as $file) {
            if (basename($file) !== 'indexer.xml') {
                continue;
            }
            $xml = Xml::loadFile($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->xpath('//indexer[@id]') as $indexer) {
                $id = (string)$indexer['id'];
                if ($id === '') {
                    continue;
                }

                $declarationFile[$id] = $file;
                $adjacency[$id] = $adjacency[$id] ?? [];

                foreach ($indexer->xpath('dependencies/indexer[@id]') as $dep) {
                    $depId = (string)$dep['id'];
                    if ($depId !== '') {
                        $adjacency[$id][] = $depId;
                    }
                }
            }
        }

        if (empty($adjacency)) {
            return;
        }

        $cycles = Graph::detectCycles($adjacency);
        foreach ($cycles as $cycle) {
            $this->reportCycle($cycle, $declarationFile);
        }

        if (!empty($this->results)) {
            echo "  \033[31m✗\033[0m Circular indexer dependencies: \033[1;31m"
                . count($cycles) . "\033[0m\n";
        }
    }

    /**
     * @param array<int, string>    $cycle
     * @param array<string, string> $declarationFile
     */
    private function reportCycle(array $cycle, array $declarationFile): void
    {
        $description = implode(' → ', array_merge($cycle, [$cycle[0]]));

        foreach ($cycle as $id) {
            $file = $declarationFile[$id] ?? null;
            if ($file === null) {
                continue;
            }
            $fileContent = file_get_contents($file);
            $line = Content::getLineNumber($fileContent, 'id="' . $id . '"');
            if ($line === 0) {
                $line = 1;
            }

            $this->foundCount++;
            $this->results[] = Formater::formatError(
                $file,
                $line,
                sprintf(
                    'Indexer "%s" is part of a circular dependency: %s. Break the cycle by removing '
                    . 'the weaker <dependencies> entry.',
                    $id,
                    $description
                ),
                'error',
                0,
                ['indexerId' => $id, 'cycle' => $cycle]
            );
        }
    }
}
