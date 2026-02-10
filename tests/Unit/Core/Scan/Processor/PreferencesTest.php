<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\Preferences;
use PHPUnit\Framework\TestCase;

class PreferencesTest extends TestCase
{
    private Preferences $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new Preferences();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/Preferences';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('duplicatePreferences', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetMessageReturnsDescription(): void
    {
        $this->assertStringContainsString('preference', strtolower($this->processor->getMessage()));
    }

    public function testProcessDetectsMultiplePreferences(): void
    {
        $multipleFile = $this->fixturesPath . '/MultiplePreferences_di.xml';
        $this->assertFileExists($multipleFile);

        $files = ['di' => [$multipleFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect duplicate preferences
        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresSinglePreference(): void
    {
        $singleFile = $this->fixturesPath . '/SinglePreferences_di.xml';
        $this->assertFileExists($singleFile);

        $files = ['di' => [$singleFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Single preference should not trigger
        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $multipleFile = $this->fixturesPath . '/MultiplePreferences_di.xml';
        $files = ['di' => [$multipleFile]];

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

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['di' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetReportEmptyWhenNoIssues(): void
    {
        $processor = new Preferences();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertEmpty($report);
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('preference', strtolower($description));
        $this->assertStringContainsString('module', strtolower($description));
    }

    public function testProcessWithScopeSpecificDiXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_pref_test_' . uniqid();
        // Create global and frontend di.xml with same preference
        mkdir($tempDir . '/etc', 0777, true);
        mkdir($tempDir . '/etc/frontend', 0777, true);

        // Global scope
        $globalDi = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <preference for="Vendor\Module\Api\ProductInterface" type="Vendor\Module\Model\Product"/>
</config>
XML;
        file_put_contents($tempDir . '/etc/di.xml', $globalDi);

        // Frontend scope - same preference but different scope, should NOT be flagged as duplicate
        $frontendDi = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <preference for="Vendor\Module\Api\ProductInterface" type="Vendor\Module\Model\FrontendProduct"/>
</config>
XML;
        file_put_contents($tempDir . '/etc/frontend/di.xml', $frontendDi);

        $processor = new Preferences();
        $files = ['di' => [
            $tempDir . '/etc/di.xml',
            $tempDir . '/etc/frontend/di.xml',
        ]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Different scopes should not be flagged as duplicates
        $this->assertEquals(0, $processor->getFoundCount());

        @unlink($tempDir . '/etc/di.xml');
        @unlink($tempDir . '/etc/frontend/di.xml');
        @rmdir($tempDir . '/etc/frontend');
        @rmdir($tempDir . '/etc');
        @rmdir($tempDir);
    }

    public function testProcessSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_pref_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $diContent = '<?xml version="1.0"?><config><preference for="broken"';
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new Preferences();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }
}
