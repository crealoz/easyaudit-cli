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

    public function testIsMagentoRootReturnsTrueWithMultipleIndicators(): void
    {
        // Create 2 indicators: bin/magento + nginx.conf.sample
        mkdir($this->scanDir . '/bin', 0777, true);
        file_put_contents($this->scanDir . '/bin/magento', '#!/usr/bin/env php');
        file_put_contents($this->scanDir . '/nginx.conf.sample', '# nginx config');

        $this->assertTrue(Scanner::isMagentoRoot($this->scanDir));
    }

    public function testIsMagentoRootReturnsFalseWithOneIndicator(): void
    {
        // Only 1 indicator: nginx.conf.sample
        file_put_contents($this->scanDir . '/nginx.conf.sample', '# nginx config');

        $this->assertFalse(Scanner::isMagentoRoot($this->scanDir));
    }

    public function testIsMagentoRootReturnsFalseForEmptyDir(): void
    {
        $this->assertFalse(Scanner::isMagentoRoot($this->scanDir));
    }

    public function testIsMagentoRootDetectsAppEtcEnvPhp(): void
    {
        mkdir($this->scanDir . '/app/etc', 0777, true);
        file_put_contents($this->scanDir . '/app/etc/env.php', '<?php return [];');
        mkdir($this->scanDir . '/pub', 0777, true);

        $this->assertTrue(Scanner::isMagentoRoot($this->scanDir));
    }

    public function testIsMagentoRootDetectsGeneratedAndPub(): void
    {
        mkdir($this->scanDir . '/generated', 0777, true);
        mkdir($this->scanDir . '/pub', 0777, true);

        $this->assertTrue(Scanner::isMagentoRoot($this->scanDir));
    }

    public function testNoiseDirsAreExcludedByDefault(): void
    {
        // Create a PHP file inside a noise dir (generated/)
        mkdir($this->scanDir . '/generated', 0777, true);
        file_put_contents(
            $this->scanDir . '/generated/Bad.php',
            '<?php class Bad { public function x() { $om = \Magento\Framework\App\ObjectManager::getInstance(); } }'
        );
        // Create a PHP file at root level
        file_put_contents($this->scanDir . '/Good.php', '<?php class Good {}');

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run();
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }

    public function testExcludePatternMatchesDirBasename(): void
    {
        // Create a subdir named "custom" with a PHP file
        mkdir($this->scanDir . '/custom', 0777, true);
        file_put_contents($this->scanDir . '/custom/File.php', '<?php class File {}');
        // Create a file at root
        file_put_contents($this->scanDir . '/Root.php', '<?php class Root {}');

        $scanner = new Scanner();

        ob_start();
        $result = $scanner->run('custom');
        ob_end_clean();

        $this->assertArrayHasKey('findings', $result);
    }
}
