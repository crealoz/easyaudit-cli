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

    public function testMetadataDistinguishesGetAndCreate(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();
        $this->assertNotEmpty($report);

        // Collect all injections from error entries
        $injections = [];
        foreach ($report[0]['files'] as $entry) {
            foreach ($entry['metadata']['injections'] as $className => $info) {
                $injections[$className] = $info;
            }
        }

        // BadObjectManagerUsage.php uses ->create(ProductRepositoryInterface::class) (short name via import)
        $this->assertArrayHasKey('ProductRepositoryInterface', $injections);
        $this->assertEquals('create', $injections['ProductRepositoryInterface']['method']);
        $this->assertArrayHasKey('property', $injections['ProductRepositoryInterface']);

        // BadObjectManagerUsage.php uses ->get(\Psr\Log\LoggerInterface::class) (FQCN inline)
        $this->assertArrayHasKey('Psr\Log\LoggerInterface', $injections);
        $this->assertEquals('get', $injections['Psr\Log\LoggerInterface']['method']);
        $this->assertArrayHasKey('property', $injections['Psr\Log\LoggerInterface']);
    }

    public function testMetadataForGetInstanceDirectUsage(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\App\ObjectManager;

class DirectGetCreate
{
    public function doStuff(): void
    {
        $repo = ObjectManager::getInstance()->create('Magento\Catalog\Model\Product');
        $logger = ObjectManager::getInstance()->get('Psr\Log\LoggerInterface');
    }
}
PHP;
        $file = $tempDir . '/DirectGetCreate.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $this->assertNotEmpty($report);

        $injections = [];
        foreach ($report[0]['files'] as $entry) {
            foreach ($entry['metadata']['injections'] as $className => $info) {
                $injections[$className] = $info;
            }
        }

        $this->assertEquals('create', $injections['Magento\Catalog\Model\Product']['method']);
        $this->assertEquals('get', $injections['Psr\Log\LoggerInterface']['method']);

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsSetupPath(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir . '/Setup/Patch/Data', 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Setup\Patch\Data;

use Magento\Framework\App\ObjectManager;

class AddData
{
    public function apply(): void
    {
        $logger = ObjectManager::getInstance()->get('Psr\Log\LoggerInterface');
        $logger->info('patch applied');
    }
}
PHP;
        $file = $tempDir . '/Setup/Patch/Data/AddData.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Setup patches should not be flagged');

        unlink($file);
        rmdir($tempDir . '/Setup/Patch/Data');
        rmdir($tempDir . '/Setup/Patch');
        rmdir($tempDir . '/Setup');
        rmdir($tempDir);
    }

    public function testProcessSkipsConsolePath(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir . '/Console/Command', 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Console\Command;

use Magento\Framework\App\ObjectManager;

class ImportCommand
{
    public function execute(): void
    {
        $logger = ObjectManager::getInstance()->get('Psr\Log\LoggerInterface');
        $logger->info('running');
    }
}
PHP;
        $file = $tempDir . '/Console/Command/ImportCommand.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Console commands should not be flagged');

        unlink($file);
        rmdir($tempDir . '/Console/Command');
        rmdir($tempDir . '/Console');
        rmdir($tempDir);
    }

    public function testGetReportEmptyWhenNoIssues(): void
    {
        $processor = new UseOfObjectManager();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertEmpty($report);
    }

    public function testProcessDetectsVariableArgumentUsage(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\ObjectManagerInterface;

class VariableUsage
{
    public function __construct(
        private ObjectManagerInterface $objectManager
    ) {
    }

    public function getService(string $className): object
    {
        return $this->objectManager->get($className);
    }
}
PHP;
        $file = $tempDir . '/VariableUsage.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Variable-argument OM usage should be detected');

        unlink($file);
        rmdir($tempDir);
    }

    public function testConfigClassVariableUsageHasNoteSeverity(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Module\Model\Config;

use Magento\Framework\Config\Data;
use Magento\Framework\ObjectManagerInterface;

class TypePool extends Data
{
    private ObjectManagerInterface $objectManager;

    public function __construct(
        ObjectManagerInterface $objectManager
    ) {
        $this->objectManager = $objectManager;
    }

    public function getInstanceByType(string $type): object
    {
        $className = $this->get($type);
        return $this->objectManager->create($className);
    }
}
PHP;
        $file = $tempDir . '/TypePool.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Config class OM usage should still be detected');

        $report = $processor->getReport();
        $this->assertNotEmpty($report);

        // Should have 'note' severity, not 'error'
        $found = false;
        foreach ($report[0]['files'] as $entry) {
            if ($entry['severity'] === 'note') {
                $found = true;
                $this->assertStringContainsString('configuration', $entry['message']);
                $this->assertStringContainsString('Factory', $entry['message']);
            }
        }
        $this->assertTrue($found, 'Config class variable OM usage should have note severity');

        unlink($file);
        rmdir($tempDir);
    }

    public function testNonConfigClassVariableUsageHasErrorSeverity(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\ObjectManagerInterface;

class ServiceLocator
{
    public function __construct(
        private ObjectManagerInterface $objectManager
    ) {
    }

    public function resolve(string $className): object
    {
        return $this->objectManager->get($className);
    }
}
PHP;
        $file = $tempDir . '/ServiceLocator.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $this->assertNotEmpty($report);

        // Should have 'error' severity for non-config class
        foreach ($report[0]['files'] as $entry) {
            $this->assertEquals('error', $entry['severity'], 'Non-config variable OM usage should be error severity');
        }

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsTestPath(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_om_test_' . uniqid();
        mkdir($tempDir . '/Test/Unit', 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Test\Unit;

use Magento\Framework\App\ObjectManager;

class SomeTest
{
    public function testSomething(): void
    {
        $logger = ObjectManager::getInstance()->get('Psr\Log\LoggerInterface');
    }
}
PHP;
        $file = $tempDir . '/Test/Unit/SomeTest.php';
        file_put_contents($file, $content);

        $processor = new UseOfObjectManager();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Test files should not be flagged');

        unlink($file);
        rmdir($tempDir . '/Test/Unit');
        rmdir($tempDir . '/Test');
        rmdir($tempDir);
    }
}
