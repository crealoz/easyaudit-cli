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
}
