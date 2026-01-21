<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\UnusedModules;
use PHPUnit\Framework\TestCase;

class UnusedModulesTest extends TestCase
{
    private UnusedModules $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new UnusedModules();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/UnusedModules';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('unusedModules', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsXml(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Unused Modules', $this->processor->getName());
    }

    public function testGetMessageContainsModule(): void
    {
        $this->assertStringContainsString('module', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('disabled', strtolower($description));
        $this->assertStringContainsString('config.php', $description);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        // Create temp structure with config.php and disabled module
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir . '/app/etc', 0777, true);
        mkdir($tempDir . '/app/code/Vendor/DisabledModule/etc', 0777, true);

        // config.php with disabled module
        $configContent = <<<'PHP'
<?php
return [
    'modules' => [
        'Vendor_DisabledModule' => 0,
        'Vendor_EnabledModule' => 1,
    ]
];
PHP;
        file_put_contents($tempDir . '/app/etc/config.php', $configContent);

        // module.xml for disabled module
        $moduleXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <module name="Vendor_DisabledModule" setup_version="1.0.0"/>
</config>
XML;
        file_put_contents($tempDir . '/app/code/Vendor/DisabledModule/etc/module.xml', $moduleXml);

        // Define constant for the processor
        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/app/code/Vendor/DisabledModule/etc/module.xml']];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        // Cleanup (tests may not reach here if constant is already defined)
        @unlink($tempDir . '/app/code/Vendor/DisabledModule/etc/module.xml');
        @unlink($tempDir . '/app/etc/config.php');
        @rmdir($tempDir . '/app/code/Vendor/DisabledModule/etc');
        @rmdir($tempDir . '/app/code/Vendor/DisabledModule');
        @rmdir($tempDir . '/app/code/Vendor');
        @rmdir($tempDir . '/app/code');
        @rmdir($tempDir . '/app/etc');
        @rmdir($tempDir . '/app');
        @rmdir($tempDir);
    }

    public function testProcessSkipsNonModuleXmlFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Create a di.xml file (not module.xml)
        $diXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="SomeClass"/>
</config>
XML;
        file_put_contents($tempDir . '/di.xml', $diXml);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/di.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Should not process di.xml files
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/di.xml');
        rmdir($tempDir);
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['xml' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoXmlFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessHandlesMalformedModuleXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Malformed module.xml
        $malformedXml = '<?xml version="1.0"?><config><module name="Test"';
        file_put_contents($tempDir . '/module.xml', $malformedXml);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Should gracefully skip malformed XML
        // Foundcount should still be 0 (no config.php found)
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/module.xml');
        rmdir($tempDir);
    }

    public function testGetReportReturnsEmptyWhenNoIssues(): void
    {
        $processor = new UnusedModules();
        $report = $processor->getReport();

        // When no issues, report should be empty
        $this->assertEmpty($report);
    }

    public function testProcessWithMissingConfigPrintsWarning(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // module.xml without config.php
        $moduleXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <module name="Test_Module" setup_version="1.0.0"/>
</config>
XML;
        file_put_contents($tempDir . '/module.xml', $moduleXml);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        $output = ob_get_clean();

        // Should print warning when config.php not found
        $this->assertStringContainsString('Warning', $output);

        unlink($tempDir . '/module.xml');
        rmdir($tempDir);
    }
}
