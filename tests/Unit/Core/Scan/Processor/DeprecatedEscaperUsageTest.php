<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\DeprecatedEscaperUsage;
use PHPUnit\Framework\TestCase;

class DeprecatedEscaperUsageTest extends TestCase
{
    private DeprecatedEscaperUsage $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new DeprecatedEscaperUsage();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/DeprecatedEscaperUsage';
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('magento.frontend.deprecated-escaper', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('phtml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertEquals('Deprecated Escaper Usage', $this->processor->getName());
    }

    public function testDetectsBlockEscapeUsage(): void
    {
        $file = $this->fixturesPath . '/Bad/deprecated_block_escape.phtml';
        $this->assertFileExists($file);

        $files = ['phtml' => [$file]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(2, $this->processor->getFoundCount());

        $report = $this->processor->getReport();
        $this->assertCount(1, $report);
        $this->assertEquals('magento.frontend.deprecated-escaper', $report[0]['ruleId']);

        // Both findings should be warnings (from $block->)
        foreach ($report[0]['files'] as $entry) {
            $this->assertEquals('warning', $entry['severity']);
            $this->assertStringContainsString('$block', $entry['message']);
        }
    }

    public function testDetectsThisEscapeUsage(): void
    {
        $file = $this->fixturesPath . '/Bad/deprecated_this_escape.phtml';
        $this->assertFileExists($file);

        $processor = new DeprecatedEscaperUsage();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(2, $processor->getFoundCount());

        $report = $processor->getReport();
        $this->assertCount(1, $report);

        // All findings should be errors (from $this->)
        foreach ($report[0]['files'] as $entry) {
            $this->assertEquals('error', $entry['severity']);
            $this->assertStringContainsString('$this', $entry['message']);
        }
    }

    public function testIgnoresCorrectEscaperUsage(): void
    {
        $file = $this->fixturesPath . '/Good/correct_escaper_usage.phtml';
        $this->assertFileExists($file);

        $processor = new DeprecatedEscaperUsage();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
        $this->assertEmpty($processor->getReport()[0]['files'] ?? []);
    }

    public function testEmptyFiles(): void
    {
        $files = ['phtml' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testNoPhtmlKey(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testLineNumbersAreAccurate(): void
    {
        $file = $this->fixturesPath . '/Bad/deprecated_block_escape.phtml';

        $processor = new DeprecatedEscaperUsage();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $entries = $report[0]['files'];

        // First match is on line 6 ($block->escapeHtml)
        $this->assertEquals(6, $entries[0]['startLine']);
        // Second match is on line 7 ($block->escapeUrl)
        $this->assertEquals(7, $entries[1]['startLine']);
    }

    public function testReportStructure(): void
    {
        $file = $this->fixturesPath . '/Bad/deprecated_block_escape.phtml';

        $processor = new DeprecatedEscaperUsage();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $this->assertCount(1, $report);

        $entry = $report[0];
        $this->assertArrayHasKey('ruleId', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('shortDescription', $entry);
        $this->assertArrayHasKey('longDescription', $entry);
        $this->assertArrayHasKey('files', $entry);
    }
}
