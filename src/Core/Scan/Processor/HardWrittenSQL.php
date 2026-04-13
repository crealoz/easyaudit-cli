<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Modules;
use EasyAudit\Service\CliWriter;

/**
 * Class HardWrittenSQL
 *
 * Detects raw SQL queries written directly in PHP code.
 * This is considered bad practice in Magento 2 as it bypasses the framework's
 * database abstraction layer, event system, and can lead to security vulnerabilities.
 *
 * Detects 5 types of SQL operations:
 * - SELECT (error): Should use repositories with getList() or getById()
 * - DELETE (error): Should use repository delete() or deleteById()
 * - INSERT (warning): Should use repository save() method
 * - UPDATE (warning): Should use repository save() method
 * - JOIN (note): Should use collection methods
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class HardWrittenSQL extends AbstractProcessor
{
    /**
     * SQL patterns to detect with their corresponding severity levels
     */
    private const SQL_PATTERNS = [
        'SELECT' => [
            'pattern' => '/SELECT\s+.*?\s+FROM/is',
            'severity' => 'high',
            'ruleId' => 'magento.code.hard-written-sql-select',
            'name' => 'Hard Written SQL SELECT',
            'shortDescription' => 'SELECT queries must be avoided',
            'longDescription' => 'Detects raw SELECT ... FROM queries in PHP code.
Impact: Raw SELECT queries bypass parameter binding and the data abstraction layer, creating SQL injection risk and making schema dependencies implicit.
Why change: Schema changes will silently break these queries. They are also invisible to Magento\'s query profiling and read/write splitting.
How to fix: Use a repository with getList()/getById(), or a collection with addFieldToFilter().',
            'recommendation' => 'Use a repository with getList() or getById() methods, or a collection with addFieldToFilter().',
        ],
        'DELETE' => [
            'pattern' => '/DELETE\s+.*?\s+FROM/is',
            'severity' => 'high',
            'ruleId' => 'magento.code.hard-written-sql-delete',
            'name' => 'Hard Written SQL DELETE',
            'shortDescription' => 'DELETE queries must be avoided',
            'longDescription' => 'Detects raw DELETE ... FROM queries in PHP code.
Impact: Raw DELETE queries bypass Magento\'s event system and referential integrity checks, risking orphaned data and silent data loss.
Why change: The framework cannot trigger after-delete observers, reindexing, or cache invalidation for rows deleted outside its control.
How to fix: Use a repository with delete()/deleteById() methods.',
            'recommendation' => 'Use a repository with delete() or deleteById() methods.',
        ],
        'INSERT' => [
            'pattern' => '/INSERT\s+.*?\s+INTO/is',
            'severity' => 'medium',
            'ruleId' => 'magento.code.hard-written-sql-insert',
            'name' => 'Hard Written SQL INSERT',
            'shortDescription' => 'INSERT queries should be avoided',
            'longDescription' => 'Detects raw INSERT ... INTO queries in PHP code.
Impact: Raw INSERT queries bypass Magento\'s validation, event dispatch, and indexing triggers. Data inserted this way is invisible to the framework.
Why change: While faster for bulk operations, the framework cannot guarantee data integrity or trigger dependent processes for these rows.
How to fix: Use a repository with save(), or the resource model\'s save() method for single entities.',
            'recommendation' => 'Use a repository with save() method or the resource model\'s save() method.',
        ],
        'UPDATE' => [
            'pattern' => '/UPDATE\s+.*?\s+SET/is',
            'severity' => 'medium',
            'ruleId' => 'magento.code.hard-written-sql-update',
            'name' => 'Hard Written SQL UPDATE',
            'shortDescription' => 'UPDATE queries should be avoided',
            'longDescription' => 'Detects raw UPDATE ... SET queries in PHP code.
Impact: Raw UPDATE queries bypass the event system and can silently overwrite data without triggering reindexing or cache invalidation.
Why change: Partial updates on error are not rolled back by the framework, and dependent data (indexes, caches) becomes stale.
How to fix: Use a repository with save(), or the resource model\'s save() method.',
            'recommendation' => 'Use a repository with save() method or the resource model\'s save() method.',
        ],
        'JOIN' => [
            'pattern' => '/\s+JOIN\s+.*?\s+ON/is',
            'severity' => 'low',
            'ruleId' => 'magento.code.hard-written-sql-join',
            'name' => 'Hard Written SQL JOIN',
            'shortDescription' => 'JOIN queries should be avoided',
            'longDescription' => 'Detects raw JOIN ... ON queries in PHP code.
Impact: Raw JOIN queries hardcode table relationships and are fragile across schema changes. They bypass the collection layer\'s query optimization and database abstraction.
Why change: Table names and column names can change between Magento versions. The collection layer handles table prefixes and EAV structure automatically.
How to fix: Use collection join() methods or addFieldToFilter() with proper table relations.',
            'recommendation' => 'Use collection join() methods or addFieldToFilter() with proper table relations.',
        ],
    ];

    /**
     * Results organized by SQL type
     */
    private array $resultsByType = [];

    public function getIdentifier(): string
    {
        return 'hard_written_sql';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Hard Written SQL';
    }

    public function getMessage(): string
    {
        return 'Detects raw SQL queries in PHP code that should use Magento\'s database abstraction layer instead.';
    }

    public function getLongDescription(): string
    {
        return 'Detects raw SQL query strings (SELECT, INSERT, UPDATE, DELETE, JOIN) written directly '
            . 'in PHP code.' . "\n"
            . 'Impact: Raw SQL bypasses parameter binding, making it a direct SQL injection vector when '
            . 'request input is involved. It is also fragile across schema changes, incompatible with '
            . 'read/write connection splitting, and invisible to Magento\'s query profiling tools.' . "\n"
            . 'Why change: Schema dependencies become implicit rather than expressed through the '
            . 'collection or repository layer, making the code harder to maintain and audit for '
            . 'security.' . "\n"
            . 'How to fix: Use repositories with SearchCriteria for reads, repository save()/delete() '
            . 'for writes, or the connection API ($connection->select(), $connection->quoteInto()) when '
            . 'lower-level access is truly needed. Never concatenate user input into query strings.';
    }

    /**
     * Process PHP files to detect hard-written SQL queries
     */
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
                $this->detectSQL($cleanedContent, $file, $fileContent);
            } catch (\InvalidArgumentException $exception) {
                CliWriter::warning('Scanner could not read content on file ' . $file . '. Error was : ' . $exception->getMessage());
                continue;
            }
        }

        $this->reportResults();
    }

    /**
     * Detect SQL patterns in the cleaned content
     */
    private function detectSQL(string $cleanedContent, string $file, string $originalContent): void
    {
        foreach (self::SQL_PATTERNS as $sqlType => $config) {
            if (preg_match_all($config['pattern'], $cleanedContent, $matches, PREG_OFFSET_CAPTURE)) {
                $this->processMatches($matches[0], $sqlType, $config, $cleanedContent, $file, $originalContent);
            }
        }
    }

    /**
     * Process regex matches for a SQL type
     */
    private function processMatches(
        array $matches,
        string $sqlType,
        array $config,
        string $cleanedContent,
        string $file,
        string $originalContent
    ): void {
        foreach ($matches as $match) {
            $matchedText = $match[0];
            $offset = $match[1];

            $lineNumber = substr_count(substr($cleanedContent, 0, $offset), "\n") + 1;
            $actualLineNumber = Content::findApproximateLine(
                $originalContent,
                $matchedText,
                $lineNumber,
                true
            );

            $message = $this->createMessage($sqlType, $matchedText, $config['recommendation']);
            $severity = $this->adjustSeverity($file, $config['severity']);

            $error = Formater::formatError($file, $actualLineNumber, $message, $severity);
            $this->storeResult($sqlType, $error);
            $this->foundCount++;
        }
    }

    /**
     * Adjust severity based on file location (Setup patches get reduced severity)
     */
    private function adjustSeverity(string $file, string $baseSeverity): string
    {
        if (Modules::isSetupDirectory($file)) {
            return 'low';
        }
        return $baseSeverity;
    }

    /**
     * Create a descriptive message for the detected SQL
     */
    private function createMessage(string $sqlType, string $matchedText, string $recommendation): string
    {
        $snippet = $this->truncateSQL($matchedText);

        return sprintf(
            'Hard-written %s query detected: "%s". %s',
            $sqlType,
            $snippet,
            $recommendation
        );
    }

    /**
     * Truncate long SQL for display in messages
     */
    private function truncateSQL(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        if (strlen($sql) > 80) {
            return substr($sql, 0, 77) . '...';
        }

        return $sql;
    }

    /**
     * Store result in the appropriate category
     */
    private function storeResult(string $sqlType, array $error): void
    {
        $this->resultsByType[$sqlType][] = $error;
    }

    /**
     * Output counts for each SQL type found
     */
    private function reportResults(): void
    {
        foreach (self::SQL_PATTERNS as $sqlType => $config) {
            if (!empty($this->resultsByType[$sqlType])) {
                CliWriter::resultLine(
                    'Hard-written ' . $sqlType . ' queries',
                    count($this->resultsByType[$sqlType]),
                    $config['severity']
                );
            }
        }
    }

    /**
     * Generate report with separate entries for each SQL type
     */
    public function getReport(): array
    {
        $report = [];

        foreach (self::SQL_PATTERNS as $sqlType => $config) {
            if (!empty($this->resultsByType[$sqlType])) {
                $report[] = [
                    'ruleId' => $config['ruleId'],
                    'name' => $config['name'],
                    'shortDescription' => $config['shortDescription'],
                    'longDescription' => $config['longDescription'],
                    'files' => $this->resultsByType[$sqlType],
                ];
            }
        }

        return $report;
    }
}
