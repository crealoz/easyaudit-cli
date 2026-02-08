<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\AdvancedBlockVsViewModel;
use PHPUnit\Framework\TestCase;

class AdvancedBlockVsViewModelTest extends TestCase
{
    private AdvancedBlockVsViewModel $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new AdvancedBlockVsViewModel();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/AdvancedBlockVsViewModel';
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('advancedBlockVsVM', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('phtml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertEquals('Block vs ViewModel', $this->processor->getName());
    }

    public function testGetMessageContainsBlockAndViewModel(): void
    {
        $message = $this->processor->getMessage();
        $this->assertStringContainsString('$this', $message);
        $this->assertStringContainsString('$block', $message);
    }

    public function testGetLongDescription(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('ViewModel', $description);
        $this->assertStringContainsString('$block', $description);
    }

    public function testProcessDetectsUseOfThis(): void
    {
        $file = $this->fixturesPath . '/bad_use_of_this.phtml';
        $this->assertFileExists($file);

        $files = ['phtml' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());

        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('thisToBlock', $ruleIds);
    }

    public function testProcessDetectsDataCrunch(): void
    {
        $file = $this->fixturesPath . '/bad_data_crunch.phtml';
        $this->assertFileExists($file);

        $processor = new AdvancedBlockVsViewModel();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('dataCrunchInPhtml', $ruleIds);
    }

    public function testProcessPassesCleanTemplateWithViewModel(): void
    {
        $file = $this->fixturesPath . '/good_with_viewmodel.phtml';
        $this->assertFileExists($file);

        $processor = new AdvancedBlockVsViewModel();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
        $this->assertEmpty($report);
    }

    public function testProcessPassesMinimalTemplate(): void
    {
        $file = $this->fixturesPath . '/good_minimal.phtml';
        $this->assertFileExists($file);

        $processor = new AdvancedBlockVsViewModel();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['phtml' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhtml(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testReportContainsSeparateRuleIds(): void
    {
        // Process both bad files to get both rule types
        $files = ['phtml' => [
            $this->fixturesPath . '/bad_use_of_this.phtml',
            $this->fixturesPath . '/bad_data_crunch.phtml',
        ]];

        $processor = new AdvancedBlockVsViewModel();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $ruleIds = array_column($report, 'ruleId');

        // Both rules should be present
        $this->assertContains('thisToBlock', $ruleIds);
        $this->assertContains('dataCrunchInPhtml', $ruleIds);

        // Each report entry should have required keys
        foreach ($report as $entry) {
            $this->assertArrayHasKey('ruleId', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('shortDescription', $entry);
            $this->assertArrayHasKey('longDescription', $entry);
            $this->assertArrayHasKey('files', $entry);
        }
    }
}
