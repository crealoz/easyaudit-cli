<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\DiAreaScope;
use PHPUnit\Framework\TestCase;

class DiAreaScopeTest extends TestCase
{
    private DiAreaScope $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new DiAreaScope();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/DiAreaScope';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('magento.di.global-area-scope', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testDetectsFrontendPluginInGlobalScope(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsAdminhtmlInGlobalScope(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/global_admin_di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresCorrectlyScoped(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Good/etc/frontend/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGlobalServicePlugin(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Good/etc/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithEmptyFiles(): void
    {
        $files = ['di' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetMessageContainsPlugin(): void
    {
        $this->assertStringContainsString('plugin', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('global', strtolower($description));
        $this->assertStringContainsString('area', strtolower($description));
    }

    public function testDetectsPreferenceInGlobalScopeForFrontendClass(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_diarea_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        // Global di.xml with preference for a frontend-only class (Block)
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <preference for="Vendor\Module\Block\ProductView" type="Vendor\Module\Block\Custom\ProductView"/>
</config>
XML;
        file_put_contents($tempDir . '/etc/di.xml', $diContent);

        $processor = new DiAreaScope();
        $files = ['di' => [$tempDir . '/etc/di.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Block classes are frontend-specific, should be flagged in global di.xml
        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($tempDir . '/etc/di.xml');
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_diarea_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $diContent = '<?xml version="1.0"?><config><type name="broken"';
        file_put_contents($tempDir . '/etc/di.xml', $diContent);

        $processor = new DiAreaScope();
        $files = ['di' => [$tempDir . '/etc/di.xml']];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($tempDir . '/etc/di.xml');
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testGetReportHasEmptyFilesWhenNoIssues(): void
    {
        $processor = new DiAreaScope();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        $this->assertEmpty($report[0]['files']);
    }

    public function testGetReportFormat(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/di.xml']];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
        }
    }
}
