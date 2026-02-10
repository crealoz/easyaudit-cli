<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\UseOfObjectManager;
use PHPUnit\Framework\TestCase;

class UseOfObjectManagerTest extends TestCase
{
    private UseOfObjectManager $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new UseOfObjectManager();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/UseOfObjectManager';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('replaceObjectManager', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Use of ObjectManager', $this->processor->getName());
    }

    public function testGetMessageReturnsDescription(): void
    {
        $this->assertStringContainsString('ObjectManager', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('dependency injection', $description);
        $this->assertStringContainsString('anti-pattern', $description);
    }

    public function testProcessDetectsViolation(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $this->assertFileExists($badFile, 'Bad fixture file should exist');

        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresCleanCode(): void
    {
        $goodFile = $this->fixturesPath . '/GoodDependencyInjection.php';
        $this->assertFileExists($goodFile, 'Good fixture file should exist');

        $files = ['php' => [$goodFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresFactoryClasses(): void
    {
        $factoryFile = $this->fixturesPath . '/ProductFactory.php';
        $this->assertFileExists($factoryFile, 'Factory fixture file should exist');

        $files = ['php' => [$factoryFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        // Factories are allowed to use ObjectManager
        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessDetectsUselessImport(): void
    {
        $uselessImportFile = $this->fixturesPath . '/UselessImport.php';
        $this->assertFileExists($uselessImportFile, 'Useless import fixture file should exist');

        $files = ['php' => [$uselessImportFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        $this->assertIsArray($report);
        $this->assertNotEmpty($report);

        $firstRule = $report[0];
        $this->assertArrayHasKey('ruleId', $firstRule);
        $this->assertArrayHasKey('name', $firstRule);
        $this->assertArrayHasKey('shortDescription', $firstRule);
        $this->assertArrayHasKey('files', $firstRule);
    }

    public function testGetReportReturnsSeparateRulesForUsageAndImport(): void
    {
        // Process both bad usage file AND useless import file
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $uselessImportFile = $this->fixturesPath . '/UselessImport.php';
        $files = ['php' => [$badFile, $uselessImportFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        // Should have 2 separate rule entries
        $this->assertCount(2, $report, 'Report should contain 2 separate rules (usage error + useless import warning)');

        // Collect rule IDs
        $ruleIds = array_column($report, 'ruleId');

        // Should have both rule types
        $this->assertContains('replaceObjectManager', $ruleIds, 'Should have replaceObjectManager rule for usage errors');
        $this->assertContains('magento.code.useless-object-manager-import', $ruleIds, 'Should have useless import rule');
    }

    public function testUselessImportRuleIdIsCorrect(): void
    {
        $uselessImportFile = $this->fixturesPath . '/UselessImport.php';
        $files = ['php' => [$uselessImportFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        $this->assertCount(1, $report, 'Should have exactly 1 rule for useless import');
        $this->assertEquals('magento.code.useless-object-manager-import', $report[0]['ruleId']);
        $this->assertEquals('Useless ObjectManager Import', $report[0]['name']);

        // Check that files have warning severity
        $this->assertNotEmpty($report[0]['files']);
        $this->assertEquals('warning', $report[0]['files'][0]['severity']);
    }

    public function testBadUsageOnlyReturnsErrorRule(): void
    {
        // When only processing the bad usage file (has import + usage)
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        // Should only have the error rule (usage), not warning (useless import)
        // because the import IS used in the bad file
        $this->assertCount(1, $report, 'Should have exactly 1 rule for bad usage');
        $this->assertEquals('replaceObjectManager', $report[0]['ruleId']);

        // Check that files have error severity
        $this->assertNotEmpty($report[0]['files']);
        $this->assertEquals('error', $report[0]['files'][0]['severity']);
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testReportContainsFileInformation(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        // Should have report entries
        $this->assertNotEmpty($report);

        // Each rule should have file information
        foreach ($report as $rule) {
            $this->assertArrayHasKey('files', $rule);
            $this->assertNotEmpty($rule['files']);
        }
    }

    public function testProcessDetectsGetInstanceUsage(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // ObjectManager::getInstance() pattern (direct, no constructor injection)
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\App\ObjectManager;

class DirectUsage
{
    public function doStuff(): void
    {
        $logger = ObjectManager::getInstance()->get('Psr\Log\LoggerInterface');
        $logger->info('test');
    }
}
PHP;
        $file = $tempDir . '/DirectUsage.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsPropertyAssignedFromGetInstance(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // $this->property = ObjectManager::getInstance() then $this->property->get(...)
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\App\ObjectManager;

class PropertyUsage
{
    private $om;

    public function initOm(): void
    {
        $this->om = ObjectManager::getInstance();
    }

    public function doStuff(): void
    {
        $logger = $this->om->get('Psr\Log\LoggerInterface');
        $logger->info('test');
    }
}
PHP;
        $file = $tempDir . '/PropertyUsage.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportEmptyWhenNoIssues(): void
    {
        $processor = new UseOfObjectManager();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertEmpty($report);
    }
}
