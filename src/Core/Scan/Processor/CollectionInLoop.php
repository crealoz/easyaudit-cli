<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Functions;
use EasyAudit\Service\CliWriter;

/**
 * Class CollectionInLoop
 *
 * Detects N+1 query patterns: model/repository loading inside loops.
 * Loading entities one by one inside loops causes severe performance issues.
 * Use collection getList() or batch loading instead.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class CollectionInLoop extends AbstractProcessor
{
    /**
     * Patterns that indicate single-entity loading (N+1 risk)
     */
    private const LOAD_PATTERNS = [
        '->load(' => 'Model ->load() call inside loop',
        '->getFirstItem()' => 'Collection ->getFirstItem() call inside loop',
        '->getById(' => 'Repository ->getById() call inside loop',
        '::load(' => 'Static Model::load() call inside loop',
        '::loadFromDb(' => 'Static ::loadFromDb() call inside loop',
    ];

    /**
     * Regex to match loop constructs and capture their bodies via brace counting
     */
    private const LOOP_PATTERN = '/\b(foreach|for|while|do)\s*[\(\{]/';

    public function getIdentifier(): string
    {
        return 'magento.performance.collection-in-loop';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Collection/Model Loading in Loop';
    }

    public function getMessage(): string
    {
        return 'Detects N+1 query patterns: model or repository loading inside loops.';
    }

    public function getLongDescription(): string
    {
        return 'Detects model or collection loading inside loops (N+1 query problem).' . "\n"
            . 'Impact: Each iteration triggers a separate SQL query. On a list of 100 items, that means '
            . '100 database round-trips. Under concurrent traffic this leads to connection pool '
            . 'exhaustion, high database CPU, and degraded response times across the entire storefront.' . "\n"
            . 'Why change: N+1 queries are one of the most frequent causes of performance degradation in '
            . 'Magento 2 and they scale linearly with dataset size, making them progressively worse as '
            . 'the catalog grows.' . "\n"
            . 'How to fix: Batch-load all needed data before the loop using getList() with SearchCriteria '
            . 'or a collection with addFieldToFilter(). Index results by entity ID for O(1) lookup '
            . 'inside the loop.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        foreach ($files['php'] as $file) {
            $fileContent = file_get_contents($file);
            if ($fileContent === false) {
                continue;
            }

            try {
                $cleanedContent = Content::removeComments($fileContent);
                if ($cleanedContent === '') {
                    continue;
                }
                $this->detectLoadInLoops($cleanedContent, $file, $fileContent);
            } catch (\InvalidArgumentException $exception) {
                CliWriter::warning('Scanner could not read content on file ' . $file . '. Error was : ' . $exception->getMessage());
                continue;
            }
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('N+1 patterns (load in loop)', count($this->results), 'medium');
        }
    }

    private function detectLoadInLoops(string $cleanedContent, string $file, string $originalContent): void
    {
        if (!preg_match_all(self::LOOP_PATTERN, $cleanedContent, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $match) {
            $loopOffset = $match[1];
            $loopBody = Functions::extractBraceBlock($cleanedContent, $loopOffset);

            if ($loopBody === null) {
                continue;
            }

            $this->checkLoopBody($loopBody, $loopOffset, $cleanedContent, $file, $originalContent);
        }
    }

    private function checkLoopBody(
        string $loopBody,
        int $loopOffset,
        string $cleanedContent,
        string $file,
        string $originalContent
    ): void {
        foreach (self::LOAD_PATTERNS as $pattern => $description) {
            if (!str_contains($loopBody, $pattern)) {
                continue;
            }

            // Find the approximate line in the cleaned content
            $patternPos = strpos($loopBody, $pattern);
            $absolutePos = strpos($cleanedContent, '{', $loopOffset) + 1 + $patternPos;
            $approximateLine = substr_count(substr($cleanedContent, 0, $absolutePos), "\n") + 1;

            // Find actual line in original content
            $lineNumber = Content::findApproximateLine($originalContent, $pattern, $approximateLine);

            $msg = "$description. This causes N+1 queries. Load all needed entities "
                . "before the loop using getList() or a filtered collection.";

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'medium');
            $this->foundCount++;
        }
    }
}
