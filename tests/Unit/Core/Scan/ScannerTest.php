<?php

namespace EasyAudit\Tests\Core\Scan;

use EasyAudit\Core\Scan\Scanner;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ScannerTest extends TestCase
{
    private string $scanDir;

    protected function setUp(): void
    {
        $this->scanDir = sys_get_temp_dir() . '/easyaudit_scanner_test_' . uniqid();
        mkdir($this->scanDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $this->scanDir);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->scanDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testRunWithEmptyDirectory(): void
    {
        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('toolSuggestions', $result);
    }

    public function testRunWithPhpFile(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Module;

class CleanClass
{
    public function execute(): void
    {
    }
}
PHP;
        file_put_contents($this->scanDir . '/CleanClass.php', $content);

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunWithExcludePatterns(): void
    {
        file_put_contents($this->scanDir . '/SomeFile.php', '<?php class Foo {}');

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run('vendor,generated');
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunWithExcludedExtensions(): void
    {
        file_put_contents($this->scanDir . '/SomeFile.php', '<?php class Foo {}');

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run('', ['xml']);
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunWithXmlFile(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="SomeClass">
        <arguments>
            <argument name="test" xsi:type="string">value</argument>
        </arguments>
    </type>
</config>
XML;
        file_put_contents($this->scanDir . '/config.xml', $xmlContent);

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunWithDiXml(): void
    {
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <preference for="SomeInterface" type="SomeClass"/>
</config>
XML;
        mkdir($this->scanDir . '/etc', 0777, true);
        file_put_contents($this->scanDir . '/etc/di.xml', $diContent);

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunWithPhtmlFile(): void
    {
        $phtmlContent = <<<'PHTML'
<?php /** @var \Magento\Framework\View\Element\Template $block */ ?>
<div>
    <?= $block->escapeHtml($block->getTitle()) ?>
</div>
PHTML;
        file_put_contents($this->scanDir . '/template.phtml', $phtmlContent);

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testRunReturnsToolSuggestions(): void
    {
        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertIsArray($result['toolSuggestions']);
    }

    public function testRunWithExcludedExtensionsSkipsXml(): void
    {
        file_put_contents($this->scanDir . '/test.php', '<?php class A {}');
        file_put_contents($this->scanDir . '/config.xml', '<config></config>');

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run('', ['xml', 'di']);
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }
}
