<?php

namespace EasyAudit\Tests\Console\Command;

use EasyAudit\Console\Command\FixApply;
use EasyAudit\Exception\CliException;
use EasyAudit\Service\Api;
use EasyAudit\Service\Logger;
use PHPUnit\Framework\TestCase;

class FixApplyTest extends TestCase
{
    private string $tmpDir;
    private string $patchDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/easyaudit_fixapply_test_' . uniqid();
        $this->patchDir = $this->tmpDir . '/patches';
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createCommand(): FixApply
    {
        $logger = $this->createMock(Logger::class);
        $api = $this->createMock(Api::class);
        return new FixApply($logger, $api);
    }

    private function createCommandWithMocks(Logger $logger, Api $api): FixApply
    {
        return new FixApply($logger, $api);
    }

    public function testGetDescriptionReturnsNonEmptyString(): void
    {
        $cmd = $this->createCommand();
        $this->assertNotEmpty($cmd->getDescription());
    }

    public function testGetSynopsisContainsFixApply(): void
    {
        $cmd = $this->createCommand();
        $this->assertStringContainsString('fix-apply', $cmd->getSynopsis());
    }

    public function testGetHelpContainsOptions(): void
    {
        $cmd = $this->createCommand();
        $help = $cmd->getHelp();
        $this->assertStringContainsString('--confirm', $help);
        $this->assertStringContainsString('--patch-out', $help);
        $this->assertStringContainsString('report.json', $help);
    }

    public function testRunWithHelpFlagReturns0(): void
    {
        $cmd = $this->createCommand();

        // fwrite(STDOUT) bypasses output buffering
        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['--help']);

        $this->assertSame(0, $result);
    }

    public function testRunWithInvalidJsonThrowsCliException(): void
    {
        $cmd = $this->createCommand();

        $reportFile = $this->tmpDir . '/bad-report.json';
        file_put_contents($reportFile, '{invalid json');

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Failed to parse JSON');

        ob_start();
        try {
            $cmd->run(['--patch-out=' . $this->patchDir, $reportFile]);
        } finally {
            ob_end_clean();
        }
    }

    public function testRunWithEmptyReportThrowsCliException(): void
    {
        $cmd = $this->createCommand();

        $reportFile = $this->tmpDir . '/empty-report.json';
        file_put_contents($reportFile, '');

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Invalid or empty report');

        ob_start();
        try {
            $cmd->run(['--patch-out=' . $this->patchDir, $reportFile]);
        } finally {
            ob_end_clean();
        }
    }

    public function testRunWithValidReportNoFixablesThrowsCliException(): void
    {
        $logger = $this->createMock(Logger::class);
        $api = $this->createMock(Api::class);
        $api->method('getAllowedType')->willReturn([]);

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => '/tmp'],
            [
                'ruleId' => 'some-rule',
                'files' => [
                    ['file' => '/tmp/test.php'],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('No fixable issues');

        ob_start();
        try {
            $cmd->run(['--confirm', '--patch-out=' . $this->patchDir, $reportFile]);
        } finally {
            ob_end_clean();
        }
    }

    public function testRunWithValidReportAndFixablesGeneratesPatches(): void
    {
        $sourceFile = $this->tmpDir . '/TestFile.php';
        file_put_contents($sourceFile, '<?php class TestFile {}');

        $logger = $this->createMock(Logger::class);
        $api = $this->createMock(Api::class);

        $api->method('getAllowedType')->willReturn([
            'magento.code.specific-class-injection' => 1,
        ]);

        $api->method('getRemainingCredits')->willReturn([
            'credits' => 100,
            'project_id' => 'test-project',
        ]);

        $api->method('requestFilefix')->willReturn([
            'diff' => "--- a/TestFile.php\n+++ b/TestFile.php\n@@ -1 +1 @@\n-<?php class TestFile {}\n+<?php class TestFile { /* fixed */ }",
            'status' => 'success',
            'credits_remaining' => 99,
        ]);

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => $this->tmpDir],
            [
                'ruleId' => 'magento.code.specific-class-injection',
                'files' => [
                    ['file' => $sourceFile, 'metadata' => ['class' => 'SomeClass']],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        ob_start();
        $result = $cmd->run([
            '--confirm',
            '--patch-out=' . $this->patchDir,
            '--scan-path=' . $this->tmpDir,
            $reportFile,
        ]);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('patch file(s)', $output);
    }

    public function testRunWithMultipleFilesAndApiError(): void
    {
        $sourceFile1 = $this->tmpDir . '/File1.php';
        $sourceFile2 = $this->tmpDir . '/File2.php';
        file_put_contents($sourceFile1, '<?php class File1 {}');
        file_put_contents($sourceFile2, '<?php class File2 {}');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('logErrors');

        $api = $this->createMock(Api::class);

        $api->method('getAllowedType')->willReturn([
            'magento.code.specific-class-injection' => 1,
        ]);

        $api->method('getRemainingCredits')->willReturn([
            'credits' => 100,
            'project_id' => 'test-project',
        ]);

        $api->method('requestFilefix')->willReturnCallback(function ($filePath) {
            if (str_contains($filePath, 'File1')) {
                return [
                    'diff' => "--- a\n+++ b\n@@ -1 +1 @@\n-old\n+new",
                    'status' => 'success',
                    'credits_remaining' => 99,
                ];
            }
            throw new \RuntimeException('API error');
        });

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => $this->tmpDir],
            [
                'ruleId' => 'magento.code.specific-class-injection',
                'files' => [
                    ['file' => $sourceFile1],
                    ['file' => $sourceFile2],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        ob_start();
        $result = $cmd->run([
            '--confirm',
            '--patch-out=' . $this->patchDir,
            '--scan-path=' . $this->tmpDir,
            $reportFile,
        ]);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('failed', $output);
    }

    public function testRunWithCreditCheckException(): void
    {
        $sourceFile = $this->tmpDir . '/TestFile.php';
        file_put_contents($sourceFile, '<?php class TestFile {}');

        $logger = $this->createMock(Logger::class);
        $api = $this->createMock(Api::class);

        $api->method('getAllowedType')->willReturn([
            'magento.code.specific-class-injection' => 1,
        ]);

        $api->method('getRemainingCredits')->willThrowException(
            new \RuntimeException('Network error')
        );

        $api->method('requestFilefix')->willReturn([
            'diff' => "--- a\n+++ b\n@@ -1 +1 @@\n-old\n+new",
            'status' => 'success',
            'credits_remaining' => 99,
        ]);

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => $this->tmpDir],
            [
                'ruleId' => 'magento.code.specific-class-injection',
                'files' => [
                    ['file' => $sourceFile],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        ob_start();
        $result = $cmd->run([
            '--confirm',
            '--patch-out=' . $this->patchDir,
            '--scan-path=' . $this->tmpDir,
            $reportFile,
        ]);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Could not check credit balance', $output);
    }

    public function testRunWithScanPathFromMetadata(): void
    {
        $sourceFile = $this->tmpDir . '/MetaFile.php';
        file_put_contents($sourceFile, '<?php class MetaFile {}');

        $logger = $this->createMock(Logger::class);
        $api = $this->createMock(Api::class);

        $api->method('getAllowedType')->willReturn([
            'magento.code.specific-class-injection' => 1,
        ]);

        $api->method('getRemainingCredits')->willReturn([
            'credits' => 50,
            'project_id' => 'meta-project',
        ]);

        $api->method('requestFilefix')->willReturn([
            'diff' => "--- a\n+++ b\n@@ -1 +1 @@\n-old\n+new",
            'status' => 'success',
            'credits_remaining' => 49,
        ]);

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => $this->tmpDir],
            [
                'ruleId' => 'magento.code.specific-class-injection',
                'files' => [
                    ['file' => $sourceFile],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        ob_start();
        $result = $cmd->run([
            '--confirm',
            '--patch-out=' . $this->patchDir,
            $reportFile,
        ]);
        ob_end_clean();

        $this->assertSame(0, $result);
    }

    public function testRunWithNoChangesLoggedOnApiException(): void
    {
        $sourceFile = $this->tmpDir . '/NoChange.php';
        file_put_contents($sourceFile, '<?php class NoChange {}');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('logNoChanges');
        $logger->expects($this->once())->method('logErrors');

        $api = $this->createMock(Api::class);

        $api->method('getAllowedType')->willReturn([
            'magento.code.specific-class-injection' => 1,
        ]);

        $api->method('getRemainingCredits')->willReturn([
            'credits' => 50,
        ]);

        $api->method('requestFilefix')->willThrowException(
            new \RuntimeException('No changes were generated for this file')
        );

        $cmd = $this->createCommandWithMocks($logger, $api);

        $report = [
            'metadata' => ['scan_path' => $this->tmpDir],
            [
                'ruleId' => 'magento.code.specific-class-injection',
                'files' => [
                    ['file' => $sourceFile],
                ],
            ],
        ];

        $reportFile = $this->tmpDir . '/report.json';
        file_put_contents($reportFile, json_encode($report));

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('No patches were generated');

        ob_start();
        try {
            $cmd->run([
                '--confirm',
                '--patch-out=' . $this->patchDir,
                '--scan-path=' . $this->tmpDir,
                $reportFile,
            ]);
        } finally {
            ob_end_clean();
        }
    }
}
