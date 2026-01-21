<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\AroundPlugins;
use PHPUnit\Framework\TestCase;

class AroundPluginsTest extends TestCase
{
    private AroundPlugins $processor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->processor = new AroundPlugins();
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function createTempFile(string $content): string
    {
        $file = $this->tempDir . '/Plugin_' . uniqid() . '.php';
        file_put_contents($file, $content);
        return $file;
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('around_plugins', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetMessageReturnsDescription(): void
    {
        $this->assertStringContainsString('around', strtolower($this->processor->getMessage()));
    }

    public function testProcessDetectsBeforePlugin(): void
    {
        // Before plugin pattern: code runs, then $proceed() is called
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class BeforePlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        $this->doSomething();
        return $proceed();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect as before plugin
        $hasBeforePlugin = false;
        foreach ($report as $rule) {
            if ($rule['ruleId'] === 'aroundToBeforePlugin') {
                $hasBeforePlugin = true;
                break;
            }
        }

        $this->assertTrue($hasBeforePlugin, 'Should detect before plugin pattern');
    }

    public function testProcessDetectsAfterPlugin(): void
    {
        // After plugin pattern: $proceed() is called first, then code runs
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class AfterPlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        $result = $proceed();
        $this->doSomethingWithResult($result);
        return $result;
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect as after plugin
        $hasAfterPlugin = false;
        foreach ($report as $rule) {
            if ($rule['ruleId'] === 'aroundToAfterPlugin') {
                $hasAfterPlugin = true;
                break;
            }
        }

        $this->assertTrue($hasAfterPlugin, 'Should detect after plugin pattern');
    }

    public function testProcessIgnoresNonPluginClasses(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Model;

class RegularClass
{
    public function getValue()
    {
        return 'value';
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class TestPlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        $this->log();
        return $proceed();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('shortDescription', $firstRule);
            $this->assertArrayHasKey('longDescription', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
        }
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }
}
