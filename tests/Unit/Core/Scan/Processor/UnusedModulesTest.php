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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProcessFlagsDisabledModuleAndPopulatesReport(): void
    {
        define('EA_SCAN_PATH', $this->fixturesPath);

        $files = [
            'xml' => [
                $this->fixturesPath . '/app/code/Vendor/DisabledModule/etc/module.xml',
                $this->fixturesPath . '/app/code/Vendor/ActiveModule/etc/module.xml',
                $this->fixturesPath . '/app/code/Vendor/AnotherActive/etc/module.xml',
            ],
        ];

        $processor = new UnusedModules();

        $this->expectOutputRegex('/Disabled modules:.*1/');
        $processor->process($files);
        $report = $processor->getReport();

        $this->assertEquals(1, $processor->getFoundCount());
        $this->assertCount(1, $report);
        $this->assertEquals('unusedModules', $report[0]['ruleId']);
        $this->assertEquals('Unused Modules', $report[0]['name']);
        $this->assertCount(1, $report[0]['files']);
        $this->assertEquals('Vendor_DisabledModule', $report[0]['files'][0]['module']);
        $this->assertEquals('low', $report[0]['files'][0]['level']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProcessIgnoresEnabledModules(): void
    {
        define('EA_SCAN_PATH', $this->fixturesPath);

        $files = [
            'xml' => [
                $this->fixturesPath . '/app/code/Vendor/ActiveModule/etc/module.xml',
                $this->fixturesPath . '/app/code/Vendor/AnotherActive/etc/module.xml',
            ],
        ];

        $processor = new UnusedModules();

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
        $this->assertEmpty($processor->getReport());
    }

    public function testProcessSkipsNonModuleXmlFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir, 0777, true);

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

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/di.xml');
        rmdir($tempDir);
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['xml' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoXmlFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProcessHandlesMalformedModuleXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir . '/app/etc', 0777, true);
        file_put_contents($tempDir . '/app/etc/config.php', "<?php\nreturn ['modules' => []];\n");

        $malformedXml = '<?xml version="1.0"?><config><module name="Test"';
        file_put_contents($tempDir . '/module.xml', $malformedXml);

        define('EA_SCAN_PATH', $tempDir);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/module.xml');
        unlink($tempDir . '/app/etc/config.php');
        rmdir($tempDir . '/app/etc');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }

    public function testGetReportReturnsEmptyWhenNoIssues(): void
    {
        $processor = new UnusedModules();
        $report = $processor->getReport();

        $this->assertEmpty($report);
    }

    public function testProcessWithMissingConfigPrintsWarning(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir, 0777, true);

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

        $this->assertStringContainsString('Warning', $output);

        unlink($tempDir . '/module.xml');
        rmdir($tempDir);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLoadMagentoConfigHandlesMissingModulesKey(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir . '/app/etc', 0777, true);
        file_put_contents(
            $tempDir . '/app/etc/config.php',
            "<?php\nreturn ['something_else' => []];\n"
        );

        $moduleXml = '<?xml version="1.0"?><config><module name="X_Y"/></config>';
        file_put_contents($tempDir . '/module.xml', $moduleXml);

        define('EA_SCAN_PATH', $tempDir);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        $output = ob_get_clean();

        $this->assertStringContainsString('Warning', $output);
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/module.xml');
        unlink($tempDir . '/app/etc/config.php');
        rmdir($tempDir . '/app/etc');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLoadMagentoConfigHandlesException(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir . '/app/etc', 0777, true);
        file_put_contents(
            $tempDir . '/app/etc/config.php',
            "<?php\nthrow new \\RuntimeException('boom');\n"
        );

        $moduleXml = '<?xml version="1.0"?><config><module name="X_Y"/></config>';
        file_put_contents($tempDir . '/module.xml', $moduleXml);

        define('EA_SCAN_PATH', $tempDir);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        $output = ob_get_clean();

        $this->assertStringContainsString('Error reading config.php', $output);
        $this->assertStringContainsString('boom', $output);
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/module.xml');
        unlink($tempDir . '/app/etc/config.php');
        rmdir($tempDir . '/app/etc');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExtractModuleNameReturnsNullWhenNameAttributeMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_unused_test_' . uniqid();
        mkdir($tempDir . '/app/etc', 0777, true);
        file_put_contents(
            $tempDir . '/app/etc/config.php',
            "<?php\nreturn ['modules' => ['Some_Module' => 0]];\n"
        );

        $moduleXml = '<?xml version="1.0"?><config><module setup_version="1.0.0"/></config>';
        file_put_contents($tempDir . '/module.xml', $moduleXml);

        define('EA_SCAN_PATH', $tempDir);

        $processor = new UnusedModules();
        $files = ['xml' => [$tempDir . '/module.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/module.xml');
        unlink($tempDir . '/app/etc/config.php');
        rmdir($tempDir . '/app/etc');
        rmdir($tempDir . '/app');
        rmdir($tempDir);
    }
}
