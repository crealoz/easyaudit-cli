<?php

namespace EasyAudit\Tests\Service;

use EasyAudit\Service\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private Logger $logger;
    private string $tempLogDir;

    protected function setUp(): void
    {
        $this->tempLogDir = sys_get_temp_dir() . '/easyaudit_logger_test_' . uniqid();
        mkdir($this->tempLogDir, 0777, true);

        // Use reflection to change the logDir property
        $this->logger = new Logger();
        $reflection = new \ReflectionClass($this->logger);
        $property = $reflection->getProperty('logDir');
        $property->setAccessible(true);
        $property->setValue($this->logger, $this->tempLogDir);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tempLogDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempLogDir);
    }

    public function testLogErrorsCreatesLogFile(): void
    {
        $errors = [
            '/path/to/file1.php' => 'Syntax error',
            '/path/to/file2.php' => 'Parse error',
        ];

        $this->logger->logErrors($errors);

        $logFile = $this->tempLogDir . '/fix-apply-errors.log';
        $this->assertFileExists($logFile);
    }

    public function testLogErrorsWritesFormattedContent(): void
    {
        $errors = [
            '/path/to/file1.php' => 'Syntax error on line 10',
        ];

        $this->logger->logErrors($errors);

        $logFile = $this->tempLogDir . '/fix-apply-errors.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('Fix-apply errors:', $content);
        $this->assertStringContainsString('File: /path/to/file1.php', $content);
        $this->assertStringContainsString('Error: Syntax error on line 10', $content);
    }

    public function testLogErrorsIncludesTimestamp(): void
    {
        $errors = ['test.php' => 'error'];

        $this->logger->logErrors($errors);

        $logFile = $this->tempLogDir . '/fix-apply-errors.log';
        $content = file_get_contents($logFile);

        // Should have a timestamp in format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }

    public function testLogErrorsAppendsToExistingFile(): void
    {
        $errors1 = ['file1.php' => 'error1'];
        $errors2 = ['file2.php' => 'error2'];

        $this->logger->logErrors($errors1);
        $this->logger->logErrors($errors2);

        $logFile = $this->tempLogDir . '/fix-apply-errors.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('file1.php', $content);
        $this->assertStringContainsString('file2.php', $content);
    }

    public function testLogErrorsHandlesEmptyArray(): void
    {
        $this->logger->logErrors([]);

        $logFile = $this->tempLogDir . '/fix-apply-errors.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('Fix-apply errors:', $content);
    }

    public function testLogNoChangesCreatesLogFile(): void
    {
        $this->logger->logNoChanges('/path/to/file.php', ['rule1' => []], '<?php class Test {}');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $this->assertFileExists($logFile);
    }

    public function testLogNoChangesWritesFilePath(): void
    {
        $filePath = '/path/to/file.php';
        $this->logger->logNoChanges($filePath, [], '<?php');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('No changes generated', $content);
        $this->assertStringContainsString('File: /path/to/file.php', $content);
    }

    public function testLogNoChangesWritesRulesAsJson(): void
    {
        $rules = [
            'useOfObjectManager' => ['line' => 10, 'class' => 'TestClass'],
        ];

        $this->logger->logNoChanges('/test.php', $rules, '<?php');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('Rules:', $content);
        $this->assertStringContainsString('useOfObjectManager', $content);
        $this->assertStringContainsString('"line": 10', $content);
    }

    public function testLogNoChangesWritesFileContent(): void
    {
        $fileContent = '<?php class Test { public function run() {} }';

        $this->logger->logNoChanges('/test.php', [], $fileContent);

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('Content:', $content);
        $this->assertStringContainsString($fileContent, $content);
    }

    public function testLogNoChangesIncludesSeparator(): void
    {
        $this->logger->logNoChanges('/test.php', [], '<?php');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        // Should have 80 = characters as separator
        $this->assertStringContainsString(str_repeat('=', 80), $content);
    }

    public function testLogNoChangesAppendsToExistingFile(): void
    {
        $this->logger->logNoChanges('/file1.php', [], 'content1');
        $this->logger->logNoChanges('/file2.php', [], 'content2');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('/file1.php', $content);
        $this->assertStringContainsString('/file2.php', $content);
    }

    public function testLogErrorsCreatesDirectoryIfNotExists(): void
    {
        // Remove the directory
        @rmdir($this->tempLogDir);

        $this->logger->logErrors(['test.php' => 'error']);

        $this->assertDirectoryExists($this->tempLogDir);
        $this->assertFileExists($this->tempLogDir . '/fix-apply-errors.log');
    }

    public function testLogNoChangesCreatesDirectoryIfNotExists(): void
    {
        // Remove the directory
        @rmdir($this->tempLogDir);

        $this->logger->logNoChanges('/test.php', [], '<?php');

        $this->assertDirectoryExists($this->tempLogDir);
        $this->assertFileExists($this->tempLogDir . '/fix-apply-no-changes.log');
    }

    public function testLogNoChangesIncludesTimestamp(): void
    {
        $this->logger->logNoChanges('/test.php', [], '<?php');

        $logFile = $this->tempLogDir . '/fix-apply-no-changes.log';
        $content = file_get_contents($logFile);

        // Should have a timestamp in format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }
}
