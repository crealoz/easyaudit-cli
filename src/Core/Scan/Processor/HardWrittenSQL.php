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
            'severity' => 'error',
            'ruleId' => 'magento.code.hard-written-sql-select',
            'name' => 'Hard Written SQL SELECT',
            'shortDescription' => 'SELECT queries must be avoided',
            'longDescription' => 'SELECT queries must be avoided. Use the Magento Framework '
                . 'methods instead or a custom repository with getList() and/or getById() '
                . 'methods. Raw SQL queries bypass Magento\'s data abstraction layer, events, '
                . 'and can lead to security vulnerabilities.',
            'recommendation' => 'Use a repository with getList() or getById() methods, or a collection with addFieldToFilter().',
        ],
        'DELETE' => [
            'pattern' => '/DELETE\s+.*?\s+FROM/is',
            'severity' => 'error',
            'ruleId' => 'magento.code.hard-written-sql-delete',
            'name' => 'Hard Written SQL DELETE',
            'shortDescription' => 'DELETE queries must be avoided',
            'longDescription' => 'DELETE queries must be avoided. Use the Magento Framework '
                . 'methods instead or a custom repository with delete() and/or deleteById() '
                . 'methods. Raw SQL deletion bypasses Magento\'s event system and can lead to '
                . 'referential integrity issues.',
            'recommendation' => 'Use a repository with delete() or deleteById() methods.',
        ],
        'INSERT' => [
            'pattern' => '/INSERT\s+.*?\s+INTO/is',
            'severity' => 'warning',
            'ruleId' => 'magento.code.hard-written-sql-insert',
            'name' => 'Hard Written SQL INSERT',
            'shortDescription' => 'INSERT queries should be avoided',
            'longDescription' => 'INSERT queries should be avoided. Use the Magento Framework '
                . 'methods instead or a custom repository with a save() method. While it can be '
                . 'faster for large amounts of data, it bypasses Magento\'s validation and '
                . 'event system.',
            'recommendation' => 'Use a repository with save() method or the resource model\'s save() method.',
        ],
        'UPDATE' => [
            'pattern' => '/UPDATE\s+.*?\s+SET/is',
            'severity' => 'warning',
            'ruleId' => 'magento.code.hard-written-sql-update',
            'name' => 'Hard Written SQL UPDATE',
            'shortDescription' => 'UPDATE queries should be avoided',
            'longDescription' => 'UPDATE queries should be avoided. Use the Magento Framework '
                . 'methods instead or a custom repository with a save() method. While it can be '
                . 'faster for large amounts of data, it can lead to data loss and bypasses '
                . 'Magento\'s event system.',
            'recommendation' => 'Use a repository with save() method or the resource model\'s save() method.',
        ],
        'JOIN' => [
            'pattern' => '/\s+JOIN\s+.*?\s+ON/is',
            'severity' => 'note',
            'ruleId' => 'magento.code.hard-written-sql-join',
            'name' => 'Hard Written SQL JOIN',
            'shortDescription' => 'JOIN queries should be avoided',
            'longDescription' => 'JOIN queries should be avoided. Use the Magento Framework '
                . 'collection methods instead, such as addFieldToFilter() or join() methods on '
                . 'collections. This ensures better performance optimization and database '
                . 'abstraction.',
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
        return 'This processor detects raw SQL queries written directly in PHP code. In '
            . 'Magento 2, raw SQL queries are considered bad practice as they bypass the '
            . 'framework\'s database abstraction layer, event system, plugins, and can lead '
            . 'to security vulnerabilities like SQL injection. Developers should use '
            . 'repositories, resource models, and collections instead.';
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
            if (Modules::isSetupDirectory($file)) {
                continue;
            }

            $fileContent = file_get_contents($file);
            if ($fileContent === false) {
                continue;
            }

            $cleanedContent = Content::removeComments($fileContent);
            $this->detectSQL($cleanedContent, $file, $fileContent);
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

            $error = Formater::formatError($file, $actualLineNumber, $message, $config['severity']);
            $this->storeResult($sqlType, $error);
            $this->foundCount++;
        }
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
