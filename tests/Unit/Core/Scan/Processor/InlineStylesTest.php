<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\InlineStyles;
use PHPUnit\Framework\TestCase;

class InlineStylesTest extends TestCase
{
    private InlineStyles $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new InlineStyles();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/InlineStyles';
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('inline_styles', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('phtml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertEquals('Inline Styles', $this->processor->getName());
    }

    public function testDetectsStyleAttributes(): void
    {
        $file = $this->fixturesPath . '/Bad/style_attribute.phtml';
        $this->assertFileExists($file);

        $files = ['phtml' => [$file]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(2, $this->processor->getFoundCount());

        $report = $this->processor->getReport();
        $this->assertCount(1, $report);
        $this->assertEquals('magento.template.inline-style-attribute', $report[0]['ruleId']);

        foreach ($report[0]['files'] as $entry) {
            $this->assertEquals('note', $entry['severity']);
        }
    }

    public function testDetectsStyleBlocks(): void
    {
        $file = $this->fixturesPath . '/Bad/style_block.phtml';
        $this->assertFileExists($file);

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(1, $processor->getFoundCount());

        $report = $processor->getReport();
        $this->assertCount(1, $report);
        $this->assertEquals('magento.template.inline-style-block', $report[0]['ruleId']);
        $this->assertEquals('warning', $report[0]['files'][0]['severity']);
    }

    public function testDetectsMixed(): void
    {
        $file = $this->fixturesPath . '/Bad/mixed.phtml';
        $this->assertFileExists($file);

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(2, $processor->getFoundCount());

        $report = $processor->getReport();
        $this->assertCount(2, $report);

        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('magento.template.inline-style-attribute', $ruleIds);
        $this->assertContains('magento.template.inline-style-block', $ruleIds);
    }

    public function testDetectsInHtmlFiles(): void
    {
        $phtmlFile = $this->fixturesPath . '/Good/clean_template.phtml';
        $htmlFile = $this->fixturesPath . '/Bad/style_attribute.html';
        $this->assertFileExists($htmlFile);

        $processor = new InlineStyles();
        $files = [
            'phtml' => [$phtmlFile],
            'html' => [$htmlFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(1, $processor->getFoundCount());

        $report = $processor->getReport();
        $this->assertCount(1, $report);
        $this->assertEquals('magento.template.inline-style-attribute', $report[0]['ruleId']);
    }

    public function testIgnoresCleanTemplates(): void
    {
        $processor = new InlineStyles();
        $files = [
            'phtml' => [$this->fixturesPath . '/Good/clean_template.phtml'],
            'html' => [$this->fixturesPath . '/Good/clean_ko.html'],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
        $this->assertEmpty($processor->getReport());
    }

    public function testSkipsEmailTemplates(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_inline_test_' . uniqid();
        mkdir($tempDir . '/email', 0777, true);

        $file = $tempDir . '/email/template.phtml';
        file_put_contents($file, '<p style="color: red">Email content</p>');

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        @unlink($file);
        @rmdir($tempDir . '/email');
        @rmdir($tempDir);
    }

    public function testSkipsPdfTemplates(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_inline_test_' . uniqid();
        mkdir($tempDir . '/pdf', 0777, true);

        $file = $tempDir . '/pdf/invoice_template.phtml';
        file_put_contents($file, '<p style="color: black">Invoice content</p>');

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        @unlink($file);
        @rmdir($tempDir . '/pdf');
        @rmdir($tempDir);
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

    public function testReportStructure(): void
    {
        $file = $this->fixturesPath . '/Bad/mixed.phtml';

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $this->assertCount(2, $report);

        foreach ($report as $entry) {
            $this->assertArrayHasKey('ruleId', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('shortDescription', $entry);
            $this->assertArrayHasKey('longDescription', $entry);
            $this->assertArrayHasKey('files', $entry);
            $this->assertNotEmpty($entry['files']);
        }
    }

    public function testLineNumbers(): void
    {
        $file = $this->fixturesPath . '/Bad/style_attribute.phtml';

        $processor = new InlineStyles();
        $files = ['phtml' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $report = $processor->getReport();
        $entries = $report[0]['files'];

        // First style attribute is on line 4
        $this->assertEquals(4, $entries[0]['startLine']);
        // Second style attribute is on line 5
        $this->assertEquals(5, $entries[1]['startLine']);
    }
}
