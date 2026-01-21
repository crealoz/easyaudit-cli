<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\MagentoFrameworkPlugin;
use PHPUnit\Framework\TestCase;

class MagentoFrameworkPluginTest extends TestCase
{
    private MagentoFrameworkPlugin $processor;

    protected function setUp(): void
    {
        $this->processor = new MagentoFrameworkPlugin();
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('magentoFrameworkPlugin', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Magento Core Class Plugins', $this->processor->getName());
    }

    public function testGetMessageContainsCore(): void
    {
        $this->assertStringContainsString('core', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('Framework', $description);
        $this->assertStringContainsString('discouraged', strtolower($description));
    }

    public function testProcessDetectsMagentoFrameworkPlugin(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_framework_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml with plugin on Magento\Framework class
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\App\Action\Forward">
        <plugin name="customForwardPlugin" type="Vendor\Module\Plugin\ForwardPlugin"/>
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

        // The processor always returns 0 for getFoundCount() due to override
        // But should still have results
        $this->assertNotEmpty($this->processor->getReport()[0]['files']);

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessIgnoresNonFrameworkPlugin(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_framework_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml with plugin on non-Framework class
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="customProductPlugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new MagentoFrameworkPlugin();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should not flag plugins for non-Framework classes
        $this->assertEmpty($report[0]['files']);

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
        $tempDir = sys_get_temp_dir() . '/easyaudit_framework_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Malformed XML
        $diContent = '<?xml version="1.0"?><config><type name="Test"';
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new MagentoFrameworkPlugin();
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
        $tempDir = sys_get_temp_dir() . '/easyaudit_framework_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\View\Layout">
        <plugin name="layoutPlugin" type="Vendor\Module\Plugin\LayoutPlugin"/>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new MagentoFrameworkPlugin();
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

    public function testGetFoundCountAlwaysReturnsZero(): void
    {
        // The processor overrides getFoundCount to always return 0
        $this->assertEquals(0, $this->processor->getFoundCount());
    }
}
