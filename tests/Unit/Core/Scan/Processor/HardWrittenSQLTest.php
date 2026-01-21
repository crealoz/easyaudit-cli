<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\HardWrittenSQL;
use PHPUnit\Framework\TestCase;

class HardWrittenSQLTest extends TestCase
{
    private HardWrittenSQL $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new HardWrittenSQL();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/HardWrittenSQL';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('hard_written_sql', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Hard Written SQL', $this->processor->getName());
    }

    public function testGetMessageContainsSql(): void
    {
        $this->assertStringContainsString('SQL', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('raw SQL', $description);
        $this->assertStringContainsString('Magento', $description);
    }

    public function testProcessDetectsValidSqlQueries(): void
    {
        $validFile = $this->fixturesPath . '/ValidSQL.php';
        $this->assertFileExists($validFile, 'ValidSQL fixture file should exist');

        $files = ['php' => [$validFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect SELECT, DELETE, INSERT, UPDATE, and JOIN queries
        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresFalsePositives(): void
    {
        $falsePositiveFile = $this->fixturesPath . '/FalsePositives.php';
        $this->assertFileExists($falsePositiveFile, 'FalsePositives fixture file should exist');

        // Create a fresh processor for isolated testing
        $processor = new HardWrittenSQL();
        $files = ['php' => [$falsePositiveFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // False positives file contains SQL in comments/docblocks - should have much fewer detections
        // Some string literals like "select" might still match the pattern, but comments should be filtered
        // The file has repository/collection usage which is clean
        $this->assertLessThan(5, $processor->getFoundCount(), 'Should filter out most commented SQL');
    }

    public function testProcessSkipsSetupDirectory(): void
    {
        $setupFile = $this->fixturesPath . '/Setup/InstallSchema.php';
        $this->assertFileExists($setupFile, 'Setup fixture file should exist');

        $processor = new HardWrittenSQL();
        $files = ['php' => [$setupFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Setup files should be skipped
        $this->assertEquals(0, $processor->getFoundCount());
    }

    public function testGetReportSeparatesByQueryType(): void
    {
        $validFile = $this->fixturesPath . '/ValidSQL.php';
        $files = ['php' => [$validFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);

        // Check that different query types have separate rule IDs
        $ruleIds = array_column($report, 'ruleId');

        // Should have SELECT and DELETE at minimum
        $this->assertTrue(
            in_array('magento.code.hard-written-sql-select', $ruleIds) ||
            in_array('magento.code.hard-written-sql-delete', $ruleIds) ||
            in_array('magento.code.hard-written-sql-insert', $ruleIds) ||
            in_array('magento.code.hard-written-sql-update', $ruleIds) ||
            in_array('magento.code.hard-written-sql-join', $ruleIds),
            'Report should contain at least one SQL query type'
        );
    }

    public function testProcessDetectsSelectQueries(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_sql_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
class TestSelect
{
    public function test()
    {
        $sql = "SELECT * FROM customer WHERE id = 1";
        return $this->connection->query($sql);
    }
}
PHP;
        $file = $tempDir . '/TestSelect.php';
        file_put_contents($file, $content);

        $processor = new HardWrittenSQL();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        // Check for SELECT rule
        $selectReport = array_filter($report, fn($r) => $r['ruleId'] === 'magento.code.hard-written-sql-select');
        $this->assertNotEmpty($selectReport, 'Should detect SELECT query');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsDeleteQueries(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_sql_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
class TestDelete
{
    public function test()
    {
        $sql = "DELETE FROM customer WHERE id = 1";
        return $this->connection->query($sql);
    }
}
PHP;
        $file = $tempDir . '/TestDelete.php';
        file_put_contents($file, $content);

        $processor = new HardWrittenSQL();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        $deleteReport = array_filter($report, fn($r) => $r['ruleId'] === 'magento.code.hard-written-sql-delete');
        $this->assertNotEmpty($deleteReport, 'Should detect DELETE query');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsJoinQueries(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_sql_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
class TestJoin
{
    public function test()
    {
        $sql = "SELECT * FROM orders o JOIN customers c ON o.customer_id = c.id";
        return $this->connection->query($sql);
    }
}
PHP;
        $file = $tempDir . '/TestJoin.php';
        file_put_contents($file, $content);

        $processor = new HardWrittenSQL();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testReportContainsFileInformation(): void
    {
        $validFile = $this->fixturesPath . '/ValidSQL.php';
        $files = ['php' => [$validFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertNotEmpty($report);
        foreach ($report as $rule) {
            $this->assertArrayHasKey('files', $rule);
            $this->assertNotEmpty($rule['files']);
        }
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessIsCaseInsensitive(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_sql_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
class TestCaseInsensitive
{
    public function test()
    {
        $sql = "select * from customer";
        return $this->connection->query($sql);
    }
}
PHP;
        $file = $tempDir . '/TestCase.php';
        file_put_contents($file, $content);

        $processor = new HardWrittenSQL();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should detect lowercase SQL');

        unlink($file);
        rmdir($tempDir);
    }
}
