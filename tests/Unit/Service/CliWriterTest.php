<?php

namespace EasyAudit\Tests\Service;

use EasyAudit\Service\CliWriter;
use PHPUnit\Framework\TestCase;

class CliWriterTest extends TestCase
{
    // --- Output methods (echo-based, tested with ob_start) ---

    public function testSuccessOutputContainsGreenCode(): void
    {
        ob_start();
        CliWriter::success('Test success');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[32m", $output);
        $this->assertStringContainsString('Test success', $output);
        $this->assertStringContainsString("\033[0m", $output);
    }

    public function testErrorOutputContainsRedCode(): void
    {
        ob_start();
        CliWriter::error('Test error');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[31m", $output);
        $this->assertStringContainsString('Test error', $output);
    }

    public function testWarningOutputContainsYellowCode(): void
    {
        ob_start();
        CliWriter::warning('Test warning');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[33m", $output);
        $this->assertStringContainsString('Test warning', $output);
    }

    public function testInfoOutputContainsBlueCode(): void
    {
        ob_start();
        CliWriter::info('Test info');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[34m", $output);
        $this->assertStringContainsString('Test info', $output);
    }

    // --- Return methods (string-based) ---

    public function testGreenReturnsColoredString(): void
    {
        $result = CliWriter::green('text');
        $this->assertStringContainsString("\033[32m", $result);
        $this->assertStringContainsString('text', $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testBlueReturnsColoredString(): void
    {
        $result = CliWriter::blue('text');
        $this->assertStringContainsString("\033[34m", $result);
        $this->assertStringContainsString('text', $result);
    }

    public function testBoldReturnsBoldString(): void
    {
        $result = CliWriter::bold('text');
        $this->assertStringContainsString("\033[1m", $result);
        $this->assertStringContainsString('text', $result);
    }

    // --- Section/Header output ---

    public function testSectionOutputsTitle(): void
    {
        ob_start();
        CliWriter::section('My Section');
        $output = ob_get_clean();

        $this->assertStringContainsString('My Section', $output);
        $this->assertStringContainsString("\033[33m", $output);
    }

    public function testHeaderOutputsBoxedTitle(): void
    {
        ob_start();
        CliWriter::header('My Header');
        $output = ob_get_clean();

        $this->assertStringContainsString('My Header', $output);
        $this->assertStringContainsString('━', $output);
    }

    public function testProcessorHeaderOutputsName(): void
    {
        ob_start();
        CliWriter::processorHeader('TestProcessor');
        $output = ob_get_clean();

        $this->assertStringContainsString('TestProcessor', $output);
        $this->assertStringContainsString('▶', $output);
    }

    // --- Line output ---

    public function testLineWithMessage(): void
    {
        ob_start();
        CliWriter::line('Hello');
        $output = ob_get_clean();

        $this->assertEquals("Hello\n", $output);
    }

    public function testLineWithoutMessage(): void
    {
        ob_start();
        CliWriter::line();
        $output = ob_get_clean();

        $this->assertEquals("\n", $output);
    }

    // --- Progress bar ---

    public function testProgressBarContainsPercentage(): void
    {
        ob_start();
        CliWriter::progressBar(5, 10, 'file.php', 'processing');
        $output = ob_get_clean();

        $this->assertStringContainsString('50%', $output);
        $this->assertStringContainsString('5/10', $output);
        $this->assertStringContainsString('processing', $output);
    }

    public function testProgressBarWithCredits(): void
    {
        ob_start();
        CliWriter::progressBar(1, 5, 'test.php', 'running', 42);
        $output = ob_get_clean();

        $this->assertStringContainsString('42 credits', $output);
    }

    public function testProgressBarTruncatesLongFilename(): void
    {
        ob_start();
        CliWriter::progressBar(1, 1, 'very/long/path/to/some/deep/file.php', 'done');
        $output = ob_get_clean();

        $this->assertStringContainsString('...', $output);
    }

    // --- Menu item ---

    public function testMenuItemWithoutCount(): void
    {
        ob_start();
        CliWriter::menuItem(1, 'Option A');
        $output = ob_get_clean();

        $this->assertStringContainsString('[', $output);
        $this->assertStringContainsString('Option A', $output);
        $this->assertStringNotContainsString('issue', $output);
    }

    public function testMenuItemWithCount(): void
    {
        ob_start();
        CliWriter::menuItem(2, 'Option B', 5);
        $output = ob_get_clean();

        $this->assertStringContainsString('Option B', $output);
        $this->assertStringContainsString('5 issues', $output);
    }

    public function testMenuItemWithSingleCount(): void
    {
        ob_start();
        CliWriter::menuItem(1, 'Option', 1);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 issue)', $output);
        $this->assertStringNotContainsString('issues', $output);
    }

    // --- Result line ---

    public function testResultLineError(): void
    {
        ob_start();
        CliWriter::resultLine('Critical issues', 3, 'error');
        $output = ob_get_clean();

        $this->assertStringContainsString('Critical issues', $output);
        $this->assertStringContainsString('3', $output);
        $this->assertStringContainsString("\033[31m", $output);
    }

    public function testResultLineWarning(): void
    {
        ob_start();
        CliWriter::resultLine('Warnings found', 2, 'warning');
        $output = ob_get_clean();

        $this->assertStringContainsString('Warnings found', $output);
        $this->assertStringContainsString("\033[33m", $output);
    }

    public function testResultLineNote(): void
    {
        ob_start();
        CliWriter::resultLine('Info items', 1, 'note');
        $output = ob_get_clean();

        $this->assertStringContainsString('Info items', $output);
        $this->assertStringContainsString("\033[34m", $output);
    }

    public function testResultLineDefaultSeverity(): void
    {
        ob_start();
        CliWriter::resultLine('Other', 0, 'other');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[32m", $output);
    }

    // --- Other methods ---

    public function testSkippedOutput(): void
    {
        ob_start();
        CliWriter::skipped('Skipped processor');
        $output = ob_get_clean();

        $this->assertStringContainsString('Skipped processor', $output);
        $this->assertStringContainsString('○', $output);
    }

    public function testLabelValueOutput(): void
    {
        ob_start();
        CliWriter::labelValue('Path', '/var/www', 'green');
        $output = ob_get_clean();

        $this->assertStringContainsString('Path', $output);
        $this->assertStringContainsString('/var/www', $output);
    }

    public function testLabelValueWithDifferentColors(): void
    {
        ob_start();
        CliWriter::labelValue('Status', 'Failed', 'red');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[31m", $output);
    }

    public function testLabelValueWithYellowColor(): void
    {
        ob_start();
        CliWriter::labelValue('Warning', 'Degraded', 'yellow');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[33m", $output);
        $this->assertStringContainsString('Warning', $output);
        $this->assertStringContainsString('Degraded', $output);
    }

    public function testLabelValueWithBlueColor(): void
    {
        ob_start();
        CliWriter::labelValue('Info', 'Details', 'blue');
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[34m", $output);
        $this->assertStringContainsString('Info', $output);
    }

    public function testClearLine(): void
    {
        ob_start();
        CliWriter::clearLine();
        $output = ob_get_clean();

        $this->assertStringContainsString("\r", $output);
    }
}
