<?php

namespace EasyAudit\Tests\Console\Command;

use EasyAudit\Console\Command\Scan;
use EasyAudit\Core\Scan\Scanner;
use PHPUnit\Framework\TestCase;

class ScanTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/easyaudit_scan_test_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
        @unlink('report/easyaudit-report.json');
        @unlink('report/easyaudit-report.sarif');
        @unlink('report/easyaudit-report.html');
        @rmdir('report');
    }

    public function testGetDescriptionReturnsNonEmptyString(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $cmd = new Scan($scanner);

        $this->assertNotEmpty($cmd->getDescription());
    }

    public function testGetSynopsisContainsScan(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $cmd = new Scan($scanner);

        $this->assertStringContainsString('scan', $cmd->getSynopsis());
    }

    public function testGetHelpContainsOptions(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $cmd = new Scan($scanner);

        $help = $cmd->getHelp();
        $this->assertStringContainsString('--format', $help);
        $this->assertStringContainsString('--exclude', $help);
        $this->assertStringContainsString('--output', $help);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithHelpFlagReturns0(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->expects($this->never())->method('run');
        $cmd = new Scan($scanner);

        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['--help']);

        $this->assertSame(0, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithShortHelpFlagReturns0(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->expects($this->never())->method('run');
        $cmd = new Scan($scanner);

        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['-h']);

        $this->assertSame(0, $result);
    }

    public function testRunWithInvalidFormatReturns1(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->expects($this->never())->method('run');
        $cmd = new Scan($scanner);

        // errorToStderr writes to STDERR via fwrite — no PHP output buffer impact
        $result = $cmd->run(['--format=xml', '/tmp']);

        $this->assertSame(1, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunReturns0OnNoIssues(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.json';

        ob_start();
        $result = $cmd->run(['--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);
        $this->assertFileExists($outputFile);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunReturns2OnErrors(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 3, 'warnings' => 1],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.json';

        ob_start();
        $result = $cmd->run(['--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(2, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunReturns1OnWarningsOnly(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 5],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.json';

        ob_start();
        $result = $cmd->run(['--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(1, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithSarifFormat(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.sarif';

        ob_start();
        $result = $cmd->run(['--format=sarif', '--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);
        $this->assertFileExists($outputFile);
        $content = file_get_contents($outputFile);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('$schema', $data);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithHtmlFormat(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.html';

        ob_start();
        $result = $cmd->run(['--format=html', '--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);
        $this->assertFileExists($outputFile);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithCustomOutputPath(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/custom-output.json';

        ob_start();
        $result = $cmd->run(['--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);
        $this->assertFileExists($outputFile);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithDefaultOutputPath(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);

        ob_start();
        $result = $cmd->run([$this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);
        $this->assertFileExists('report/easyaudit-report.json');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithToolSuggestionsPrintsExternalTools(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [
                'magento.code.useless-object-manager-import' => 5,
            ],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.json';

        ob_start();
        $result = $cmd->run(['--output=' . $outputFile, $this->tmpDir]);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('External tool suggestions', $output);
        $this->assertStringContainsString('php-cs-fixer', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRunWithProjectName(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('run')->willReturn([
            'findings' => [
                'summary' => ['errors' => 0, 'warnings' => 0],
            ],
            'toolSuggestions' => [],
        ]);

        $cmd = new Scan($scanner);
        $outputFile = $this->tmpDir . '/test-report.json';

        ob_start();
        $result = $cmd->run(['--project-name=my-project', '--output=' . $outputFile, $this->tmpDir]);
        ob_end_clean();

        $this->assertSame(0, $result);

        $content = file_get_contents($outputFile);
        $data = json_decode($content, true);
        $this->assertArrayHasKey('metadata', $data);
        $this->assertStringContainsString('my-project', $data['metadata']['project_id']);
    }
}
