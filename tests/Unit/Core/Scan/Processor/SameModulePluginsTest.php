<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\SameModulePlugins;
use PHPUnit\Framework\TestCase;

class SameModulePluginsTest extends TestCase
{
    private SameModulePlugins $processor;

    protected function setUp(): void
    {
        $this->processor = new SameModulePlugins();
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('sameModulePlugin', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Same Module Plugins', $this->processor->getName());
    }

    public function testGetMessageContainsPlugin(): void
    {
        $this->assertStringContainsString('plugin', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('plugin', strtolower($description));
        $this->assertStringContainsString('same module', strtolower($description));
    }

    public function testProcessDetectsSameModulePlugin(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_samemod_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml with plugin in same module (Vendor_Module plugs Vendor_Module)
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Vendor\Module\Model\Product">
        <plugin name="vendorModuleProductPlugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $files = ['di' => [$diFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect same module plugin
        $this->assertGreaterThan(0, $this->processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessIgnoresDifferentModulePlugin(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_samemod_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml with plugin in different module
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="customPlugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new SameModulePlugins();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should not flag plugins for different modules
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessIgnoresDisabledPlugins(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_samemod_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml with disabled plugin in same module
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Vendor\Module\Model\Product">
        <plugin name="vendorModuleProductPlugin" type="Vendor\Module\Plugin\ProductPlugin" disabled="true"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new SameModulePlugins();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Disabled plugins should not be flagged
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessWithEmptyDiFiles(): void
    {
        $files = ['di' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoDiFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_samemod_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Malformed XML
        $diContent = '<?xml version="1.0"?><config><type name="Test"';
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new SameModulePlugins();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should gracefully skip malformed XML
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_samemod_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Vendor\Module\Model\Product">
        <plugin name="vendorPlugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new SameModulePlugins();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
        }

        unlink($diFile);
        rmdir($tempDir);
    }
}
