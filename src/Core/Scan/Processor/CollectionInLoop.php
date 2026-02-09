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
        return 'Loading models or fetching single entities inside loops causes N+1 query '
            . 'problems, one of the most common performance killers in Magento 2. Each '
            . 'iteration executes a separate database query, leading to potentially hundreds '
            . 'or thousands of queries. Instead, use collection getList() with search criteria '
            . 'to batch-load all needed entities before the loop, or use a collection with '
            . 'addFieldToFilter() to load all items at once.';
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

            $cleanedContent = Content::removeComments($fileContent);
            $this->detectLoadInLoops($cleanedContent, $file, $fileContent);
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('N+1 patterns (load in loop)', count($this->results), 'warning');
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

            $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'warning');
            $this->foundCount++;
        }
    }
}
