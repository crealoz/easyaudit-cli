<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\BlockViewModelRatio;
use PHPUnit\Framework\TestCase;

class BlockViewModelRatioTest extends TestCase
{
    private BlockViewModelRatio $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new BlockViewModelRatio();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/BlockViewModelRatio';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('blockViewModelRatio', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Block vs ViewModel Ratio', $this->processor->getName());
    }

    public function testGetMessageContainsRatio(): void
    {
        $this->assertStringContainsString('ratio', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('ViewModel', $description);
        $this->assertStringContainsString('Block', $description);
    }

    public function testProcessDetectsHighBlockRatio(): void
    {
        // Create temp files simulating a module with high block ratio
        $tempDir = sys_get_temp_dir() . '/easyaudit_ratio_test_' . uniqid();
        $baseDir = $tempDir . '/app/code/Vendor/BadModule';
        mkdir($baseDir . '/Block', 0777, true);
        mkdir($baseDir . '/Model', 0777, true);

        // Create 3 block files (more than 50%)
        file_put_contents($baseDir . '/Block/Product.php', '<?php class Product {}');
        file_put_contents($baseDir . '/Block/Category.php', '<?php class Category {}');
        file_put_contents($baseDir . '/Block/Custom.php', '<?php class Custom {}');

        // Create 1 model file
        file_put_contents($baseDir . '/Model/Data.php', '<?php class Data {}');

        $files = [
            'php' => [
                $baseDir . '/Block/Product.php',
                $baseDir . '/Block/Category.php',
                $baseDir . '/Block/Custom.php',
                $baseDir . '/Model/Data.php',
            ]
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect high block ratio (75%)
        $this->assertGreaterThan(0, $this->processor->getFoundCount());

        // Cleanup
        unlink($baseDir . '/Block/Product.php');
        unlink($baseDir . '/Block/Category.php');
        unlink($baseDir . '/Block/Custom.php');
        unlink($baseDir . '/Model/Data.php');
        rmdir($baseDir . '/Block');
        rmdir($baseDir . '/Model');
        rmdir($baseDir);
        rmdir($tempDir . '/app/code/Vendor');
        rmdir($tempDir . '/app/code');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }

    public function testProcessIgnoresGoodRatio(): void
    {
        // Create temp files simulating a module with good ratio
        $tempDir = sys_get_temp_dir() . '/easyaudit_ratio_test_' . uniqid();
        $baseDir = $tempDir . '/app/code/Vendor/GoodModule';
        mkdir($baseDir . '/Block', 0777, true);
        mkdir($baseDir . '/ViewModel', 0777, true);
        mkdir($baseDir . '/Model', 0777, true);

        // Create 1 block file
        file_put_contents($baseDir . '/Block/Product.php', '<?php class Product {}');

        // Create 2 viewmodel files
        file_put_contents($baseDir . '/ViewModel/ProductDetails.php', '<?php class ProductDetails {}');
        file_put_contents($baseDir . '/ViewModel/CategoryList.php', '<?php class CategoryList {}');

        // Create 2 model files
        file_put_contents($baseDir . '/Model/Data.php', '<?php class Data {}');
        file_put_contents($baseDir . '/Model/Handler.php', '<?php class Handler {}');

        $processor = new BlockViewModelRatio();
        $files = [
            'php' => [
                $baseDir . '/Block/Product.php',
                $baseDir . '/ViewModel/ProductDetails.php',
                $baseDir . '/ViewModel/CategoryList.php',
                $baseDir . '/Model/Data.php',
                $baseDir . '/Model/Handler.php',
            ]
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should not flag (20% blocks ratio)
        $this->assertEquals(0, $processor->getFoundCount());

        // Cleanup
        unlink($baseDir . '/Block/Product.php');
        unlink($baseDir . '/ViewModel/ProductDetails.php');
        unlink($baseDir . '/ViewModel/CategoryList.php');
        unlink($baseDir . '/Model/Data.php');
        unlink($baseDir . '/Model/Handler.php');
        rmdir($baseDir . '/Block');
        rmdir($baseDir . '/ViewModel');
        rmdir($baseDir . '/Model');
        rmdir($baseDir);
        rmdir($tempDir . '/app/code/Vendor');
        rmdir($tempDir . '/app/code');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ratio_test_' . uniqid();
        $baseDir = $tempDir . '/app/code/Vendor/Module';
        mkdir($baseDir . '/Block', 0777, true);

        // Create only block files (100% ratio)
        file_put_contents($baseDir . '/Block/One.php', '<?php class One {}');
        file_put_contents($baseDir . '/Block/Two.php', '<?php class Two {}');

        $files = [
            'php' => [
                $baseDir . '/Block/One.php',
                $baseDir . '/Block/Two.php',
            ]
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('shortDescription', $firstRule);
            $this->assertArrayHasKey('longDescription', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
            $this->assertEquals('blockViewModelRatio', $firstRule['ruleId']);
        }

        // Cleanup
        unlink($baseDir . '/Block/One.php');
        unlink($baseDir . '/Block/Two.php');
        rmdir($baseDir . '/Block');
        rmdir($baseDir);
        rmdir($tempDir . '/app/code/Vendor');
        rmdir($tempDir . '/app/code');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
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

    public function testReportContainsModuleInformation(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ratio_test_' . uniqid();
        $baseDir = $tempDir . '/app/code/TestVendor/TestModule';
        mkdir($baseDir . '/Block', 0777, true);

        // Create block files only
        file_put_contents($baseDir . '/Block/Main.php', '<?php class Main {}');

        $processor = new BlockViewModelRatio();
        $files = [
            'php' => [
                $baseDir . '/Block/Main.php',
            ]
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertNotEmpty($report);
        $moduleRatios = $report[0]['files'];
        $this->assertNotEmpty($moduleRatios);

        // Check that module name is captured
        $moduleInfo = $moduleRatios[0];
        $this->assertArrayHasKey('module', $moduleInfo);
        $this->assertStringContainsString('TestVendor_TestModule', $moduleInfo['module']);

        // Cleanup
        unlink($baseDir . '/Block/Main.php');
        rmdir($baseDir . '/Block');
        rmdir($baseDir);
        rmdir($tempDir . '/app/code/TestVendor');
        rmdir($tempDir . '/app/code');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }
}
