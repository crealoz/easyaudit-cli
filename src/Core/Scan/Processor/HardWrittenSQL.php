<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

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
            'longDescription' => 'SELECT queries must be avoided. Use the Magento Framework methods instead or a custom repository with getList() and/or getById() methods. Raw SQL queries bypass Magento\'s data abstraction layer, events, and can lead to security vulnerabilities.',
        ],
        'DELETE' => [
            'pattern' => '/DELETE\s+.*?\s+FROM/is',
            'severity' => 'error',
            'ruleId' => 'magento.code.hard-written-sql-delete',
            'name' => 'Hard Written SQL DELETE',
            'shortDescription' => 'DELETE queries must be avoided',
            'longDescription' => 'DELETE queries must be avoided. Use the Magento Framework methods instead or a custom repository with delete() and/or deleteById() methods. Raw SQL deletion bypasses Magento\'s event system and can lead to referential integrity issues.',
        ],
        'INSERT' => [
            'pattern' => '/INSERT\s+.*?\s+INTO/is',
            'severity' => 'warning',
            'ruleId' => 'magento.code.hard-written-sql-insert',
            'name' => 'Hard Written SQL INSERT',
            'shortDescription' => 'INSERT queries should be avoided',
            'longDescription' => 'INSERT queries should be avoided. Use the Magento Framework methods instead or a custom repository with a save() method. While it can be faster for large amounts of data, it bypasses Magento\'s validation and event system.',
        ],
        'UPDATE' => [
            'pattern' => '/UPDATE\s+.*?\s+SET/is',
            'severity' => 'warning',
            'ruleId' => 'magento.code.hard-written-sql-update',
            'name' => 'Hard Written SQL UPDATE',
            'shortDescription' => 'UPDATE queries should be avoided',
            'longDescription' => 'UPDATE queries should be avoided. Use the Magento Framework methods instead or a custom repository with a save() method. While it can be faster for large amounts of data, it can lead to data loss and bypasses Magento\'s event system.',
        ],
        'JOIN' => [
            'pattern' => '/\s+JOIN\s+.*?\s+ON/is',
            'severity' => 'note',
            'ruleId' => 'magento.code.hard-written-sql-join',
            'name' => 'Hard Written SQL JOIN',
            'shortDescription' => 'JOIN queries should be avoided',
            'longDescription' => 'JOIN queries should be avoided. Use the Magento Framework collection methods instead, such as addFieldToFilter() or join() methods on collections. This ensures better performance optimization and database abstraction.',
        ],
    ];

    /**
     * Results organized by SQL type and severity
     */
    private array $selectResults = [];
    private array $deleteResults = [];
    private array $insertResults = [];
    private array $updateResults = [];
    private array $joinResults = [];

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
        return 'This processor detects raw SQL queries written directly in PHP code. In Magento 2, raw SQL queries are considered bad practice as they bypass the framework\'s database abstraction layer, event system, plugins, and can lead to security vulnerabilities like SQL injection. Developers should use repositories, resource models, and collections instead.';
    }

    /**
     * Process PHP files to detect hard-written SQL queries
     *
     * @param array $files Array of files grouped by type
     */
    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        foreach ($files['php'] as $file) {
            // Skip Setup directories (install scripts, patches, migrations)
            if ($this->isSetupDirectory($file)) {
                continue;
            }

            $fileContent = file_get_contents($file);
            if ($fileContent === false) {
                continue;
            }

            // Remove comments and docblocks to avoid false positives
            $cleanedContent = $this->removeComments($fileContent);

            // Check for each SQL pattern
            $this->detectSQL($cleanedContent, $file, $fileContent);
        }
    }

    /**
     * Check if file is in a Setup directory (should be excluded from SQL checks)
     *
     * @param string $filePath
     * @return bool
     */
    private function isSetupDirectory(string $filePath): bool
    {
        return str_contains($filePath, '/Setup/') ||
               str_contains($filePath, DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR);
    }

    /**
     * Remove comments and docblocks from code to avoid false positives
     *
     * @param string $content
     * @return string
     */
    private function removeComments(string $content): string
    {
        // Remove multi-line comments /* ... */
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);

        // Remove single-line comments //
        $content = preg_replace('/\/\/.*?$/m', '', $content);

        // Remove hash comments #
        $content = preg_replace('/#.*?$/m', '', $content);

        return $content;
    }

    /**
     * Detect SQL patterns in the cleaned content
     *
     * @param string $cleanedContent Content with comments removed
     * @param string $file File path
     * @param string $originalContent Original file content for line number extraction
     */
    private function detectSQL(string $cleanedContent, string $file, string $originalContent): void
    {
        foreach (self::SQL_PATTERNS as $sqlType => $config) {
            if (preg_match_all($config['pattern'], $cleanedContent, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $matchedText = $match[0];
                    $offset = $match[1];

                    // Calculate line number from offset
                    $lineNumber = substr_count(substr($cleanedContent, 0, $offset), "\n") + 1;

                    // Get the actual line number in the original file
                    // We need to find the matched text in the original content to get accurate line numbers
                    $actualLineNumber = $this->findLineNumber($originalContent, $matchedText, $lineNumber);

                    // Create detailed message
                    $message = $this->createMessage($sqlType, $matchedText);

                    // Format error
                    $error = Formater::formatError(
                        $file,
                        $actualLineNumber,
                        $message,
                        $config['severity']
                    );

                    // Store result in appropriate array
                    $this->storeResult($sqlType, $error);
                    $this->foundCount++;
                }
            }
        }
    }

    /**
     * Find the actual line number in the original content
     * This is needed because we removed comments, which shifts line numbers
     *
     * @param string $originalContent
     * @param string $matchedText
     * @param int $approximateLine
     * @return int
     */
    private function findLineNumber(string $originalContent, string $matchedText, int $approximateLine): int
    {
        // Try to find the exact match in the original content
        $lines = explode("\n", $originalContent);

        // Search around the approximate line (within a range of +/- 10 lines)
        $searchStart = max(0, $approximateLine - 10);
        $searchEnd = min(count($lines), $approximateLine + 10);

        for ($i = $searchStart; $i < $searchEnd; $i++) {
            // Remove extra whitespace for comparison
            $normalizedLine = preg_replace('/\s+/', ' ', $lines[$i]);
            $normalizedMatch = preg_replace('/\s+/', ' ', $matchedText);

            if (str_contains($normalizedLine, trim($normalizedMatch))) {
                return $i + 1; // Line numbers start at 1
            }
        }

        // If we can't find it, return the approximate line
        return $approximateLine;
    }

    /**
     * Create a descriptive message for the detected SQL
     *
     * @param string $sqlType
     * @param string $matchedText
     * @return string
     */
    private function createMessage(string $sqlType, string $matchedText): string
    {
        $snippet = $this->truncateSQL($matchedText);

        $recommendations = [
            'SELECT' => 'Use a repository with getList() or getById() methods, or a collection with addFieldToFilter().',
            'DELETE' => 'Use a repository with delete() or deleteById() methods.',
            'INSERT' => 'Use a repository with save() method or the resource model\'s save() method.',
            'UPDATE' => 'Use a repository with save() method or the resource model\'s save() method.',
            'JOIN' => 'Use collection join() methods or addFieldToFilter() with proper table relations.',
        ];

        return sprintf(
            'Hard-written %s query detected: "%s". %s',
            $sqlType,
            $snippet,
            $recommendations[$sqlType] ?? 'Use Magento\'s database abstraction layer.'
        );
    }

    /**
     * Truncate long SQL for display in messages
     *
     * @param string $sql
     * @return string
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
     * Store result in the appropriate array based on SQL type
     *
     * @param string $sqlType
     * @param array $error
     */
    private function storeResult(string $sqlType, array $error): void
    {
        switch ($sqlType) {
            case 'SELECT':
                $this->selectResults[] = $error;
                break;
            case 'DELETE':
                $this->deleteResults[] = $error;
                break;
            case 'INSERT':
                $this->insertResults[] = $error;
                break;
            case 'UPDATE':
                $this->updateResults[] = $error;
                break;
            case 'JOIN':
                $this->joinResults[] = $error;
                break;
        }
    }

    /**
     * Generate report with separate entries for each SQL type
     *
     * @return array
     */
    public function getReport(): array
    {
        $report = [];

        if (!empty($this->selectResults)) {
            $report[] = [
                'ruleId' => self::SQL_PATTERNS['SELECT']['ruleId'],
                'name' => self::SQL_PATTERNS['SELECT']['name'],
                'shortDescription' => self::SQL_PATTERNS['SELECT']['shortDescription'],
                'longDescription' => self::SQL_PATTERNS['SELECT']['longDescription'],
                'files' => $this->selectResults,
            ];
        }

        if (!empty($this->deleteResults)) {
            $report[] = [
                'ruleId' => self::SQL_PATTERNS['DELETE']['ruleId'],
                'name' => self::SQL_PATTERNS['DELETE']['name'],
                'shortDescription' => self::SQL_PATTERNS['DELETE']['shortDescription'],
                'longDescription' => self::SQL_PATTERNS['DELETE']['longDescription'],
                'files' => $this->deleteResults,
            ];
        }

        if (!empty($this->insertResults)) {
            $report[] = [
                'ruleId' => self::SQL_PATTERNS['INSERT']['ruleId'],
                'name' => self::SQL_PATTERNS['INSERT']['name'],
                'shortDescription' => self::SQL_PATTERNS['INSERT']['shortDescription'],
                'longDescription' => self::SQL_PATTERNS['INSERT']['longDescription'],
                'files' => $this->insertResults,
            ];
        }

        if (!empty($this->updateResults)) {
            $report[] = [
                'ruleId' => self::SQL_PATTERNS['UPDATE']['ruleId'],
                'name' => self::SQL_PATTERNS['UPDATE']['name'],
                'shortDescription' => self::SQL_PATTERNS['UPDATE']['shortDescription'],
                'longDescription' => self::SQL_PATTERNS['UPDATE']['longDescription'],
                'files' => $this->updateResults,
            ];
        }

        if (!empty($this->joinResults)) {
            $report[] = [
                'ruleId' => self::SQL_PATTERNS['JOIN']['ruleId'],
                'name' => self::SQL_PATTERNS['JOIN']['name'],
                'shortDescription' => self::SQL_PATTERNS['JOIN']['shortDescription'],
                'longDescription' => self::SQL_PATTERNS['JOIN']['longDescription'],
                'files' => $this->joinResults,
            ];
        }

        return $report;
    }
}
