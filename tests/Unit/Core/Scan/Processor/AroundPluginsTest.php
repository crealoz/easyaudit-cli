<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\AroundPlugins;
use EasyAudit\Core\Scan\Scanner;
use EasyAudit\Core\Scan\Util\PluginRegistry;
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
        Scanner::setGeneratedPath(null);
        PluginRegistry::reset();
        // Clean up temp files
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
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

    public function testProcessDetectsOverrideWhenNoCallableFound(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class OverridePlugin
{
    public function aroundGetValue($subject)
    {
        return 'overridden';
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $hasOverride = false;
        foreach ($report as $rule) {
            if ($rule['ruleId'] === 'overrideNotPlugin') {
                $hasOverride = true;
                break;
            }
        }

        $this->assertTrue($hasOverride, 'Should detect override pattern (no callable)');
    }

    public function testProcessDetectsProceedConvention(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class ProceedPlugin
{
    public function aroundSave($subject, $proceed)
    {
        $result = $proceed();
        $this->log('saved');
        return $result;
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());
    }

    public function testProcessHandlesMultipleAroundMethods(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class MultiPlugin
{
    public function aroundGetName($subject, callable $proceed)
    {
        $result = $proceed();
        return strtoupper($result);
    }

    public function aroundSetName($subject, callable $proceed, $name)
    {
        $this->validate($name);
        return $proceed();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThanOrEqual(2, $processor->getFoundCount());
    }

    public function testReportContainsAllThreeCategories(): void
    {
        // Create files that trigger all three categories
        $afterContent = <<<'PHP'
<?php
namespace Test\Plugin;

class AfterP
{
    public function aroundGetA($subject, callable $proceed)
    {
        $result = $proceed();
        $this->doAfter($result);
        return $result;
    }
}
PHP;

        $beforeContent = <<<'PHP'
<?php
namespace Test\Plugin;

class BeforeP
{
    public function aroundGetB($subject, callable $proceed)
    {
        $this->doBefore();
        return $proceed();
    }
}
PHP;

        $overrideContent = <<<'PHP'
<?php
namespace Test\Plugin;

class OverrideP
{
    public function aroundGetC($subject)
    {
        return 'overridden';
    }
}
PHP;

        $file1 = $this->createTempFile($afterContent);
        $file2 = $this->createTempFile($beforeContent);
        $file3 = $this->createTempFile($overrideContent);

        $processor = new AroundPlugins();
        $files = ['php' => [$file1, $file2, $file3]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('aroundToAfterPlugin', $ruleIds);
        $this->assertContains('aroundToBeforePlugin', $ruleIds);
        $this->assertContains('overrideNotPlugin', $ruleIds);
    }

    public function testSkipsConditionalTernary(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class ConditionalPlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        return ($this->permission->isAllAllowed()) ? true : $proceed();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Conditional ternary $proceed() should not be flagged');
    }

    public function testSkipsConditionalIfElse(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class IfElsePlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        if ($this->isEnabled()) {
            return $proceed();
        }
        return 'default';
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Conditional if/else $proceed() should not be flagged');
    }

    public function testSkipsShortCircuit(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class ShortCircuitPlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        return $this->condition && $proceed();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Short-circuit $proceed() should not be flagged');
    }

    public function testDoesNotSkipTryCatchProceed(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class TryCatchPlugin
{
    public function aroundGetValue($subject, callable $proceed)
    {
        try {
            $result = $proceed();
        } catch (\Exception $e) {
            $result = 'fallback';
        }
        return $result;
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'try/catch wrapped $proceed() should still be detected');
        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('aroundToAfterPlugin', $ruleIds, 'Should be classified as after plugin');
    }

    public function testProcessDetectsAfterPluginWithMultiLineSignature(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class MultiLinePlugin
{
    public function aroundAddProduct(
        \Magento\Quote\Model\Quote $subject,
        callable $proceed,
        \Magento\Catalog\Model\Product $product,
        $request = null,
        $processMode = \Magento\Catalog\Model\Product\Type\AbstractType::PROCESS_MODE_FULL
    ) {
        $result = $proceed($product, $request, $processMode);
        return $result;
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Multi-line signature should be detected');
        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('aroundToAfterPlugin', $ruleIds, 'Should be classified as after plugin');
    }

    public function testGetLongDescription(): void
    {
        $desc = $this->processor->getLongDescription();
        $this->assertStringContainsString('around', strtolower($desc));
        $this->assertStringContainsString('overhead', strtolower($desc));
    }

    public function testProcessWithClosureTypeHint(): void
    {
        $content = <<<'PHP'
<?php
namespace Test\Plugin;

class ClosurePlugin
{
    public function aroundExecute($subject, \Closure $closure)
    {
        $this->before();
        return $closure();
    }
}
PHP;

        $file = $this->createTempFile($content);
        $files = ['php' => [$file]];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());
    }

    public function testDeriveOriginalMethodName(): void
    {
        $this->assertEquals('getValue', AroundPlugins::deriveOriginalMethodName('aroundGetValue'));
        $this->assertEquals('save', AroundPlugins::deriveOriginalMethodName('aroundSave'));
        $this->assertEquals('setName', AroundPlugins::deriveOriginalMethodName('aroundSetName'));
    }

    public function testDeepStackDetectionWithInterceptor(): void
    {
        // Set up generated directory with an interceptor
        $generatedDir = $this->tempDir . '/generated/code';
        $interceptorDir = $generatedDir . '/Magento/Catalog/Model/Product';
        mkdir($interceptorDir, 0777, true);

        $interceptorContent = <<<'PHP'
<?php
namespace Magento\Catalog\Model\Product;

class Interceptor extends \Magento\Catalog\Model\Product
{
    public function save()
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'save');
        return $this->___callPlugins('save', func_get_args(), $pluginInfo);
    }
}
PHP;
        file_put_contents($interceptorDir . '/Interceptor.php', $interceptorContent);
        Scanner::setGeneratedPath($generatedDir);

        // Create two plugin files that both have aroundSave
        $pluginA = <<<'PHP'
<?php
namespace Vendor\ModuleA\Plugin;

class ProductPlugin
{
    public function aroundSave($subject, callable $proceed)
    {
        $this->doSomething();
        return $proceed();
    }
}
PHP;

        $pluginB = <<<'PHP'
<?php
namespace Vendor\ModuleB\Plugin;

class ProductPlugin
{
    public function aroundSave($subject, callable $proceed)
    {
        $result = $proceed();
        $this->doSomethingElse();
        return $result;
    }
}
PHP;

        $fileA = $this->tempDir . '/PluginA.php';
        $fileB = $this->tempDir . '/PluginB.php';
        file_put_contents($fileA, $pluginA);
        file_put_contents($fileB, $pluginB);

        // Create di.xml that maps both plugins to the same target
        $diXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_a" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
        <plugin name="plugin_b" type="Vendor\ModuleB\Plugin\ProductPlugin"/>
    </type>
</config>
XML;

        $diFile = $this->tempDir . '/di.xml';
        file_put_contents($diFile, $diXml);

        $files = [
            'php' => [$fileA, $fileB],
            'di' => [$diFile],
        ];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('deepPluginStack', $ruleIds, 'Should detect deep plugin stack');

        // Find the deepPluginStack report
        $deepStackReport = null;
        foreach ($report as $rule) {
            if ($rule['ruleId'] === 'deepPluginStack') {
                $deepStackReport = $rule;
                break;
            }
        }

        $this->assertNotNull($deepStackReport);
        $this->assertNotEmpty($deepStackReport['files']);
        $this->assertStringContainsString('2 around plugins', $deepStackReport['files'][0]['message']);
        $this->assertStringContainsString('save()', $deepStackReport['files'][0]['message']);
    }

    public function testNoDeepStackWithoutGeneratedDir(): void
    {
        Scanner::setGeneratedPath(null);

        $pluginA = <<<'PHP'
<?php
namespace Vendor\ModuleA\Plugin;

class ProductPlugin
{
    public function aroundSave($subject, callable $proceed)
    {
        $this->doSomething();
        return $proceed();
    }
}
PHP;

        $pluginB = <<<'PHP'
<?php
namespace Vendor\ModuleB\Plugin;

class ProductPlugin
{
    public function aroundSave($subject, callable $proceed)
    {
        $result = $proceed();
        return $result;
    }
}
PHP;

        $fileA = $this->tempDir . '/PluginA.php';
        $fileB = $this->tempDir . '/PluginB.php';
        file_put_contents($fileA, $pluginA);
        file_put_contents($fileB, $pluginB);

        $diXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_a" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
        <plugin name="plugin_b" type="Vendor\ModuleB\Plugin\ProductPlugin"/>
    </type>
</config>
XML;

        $diFile = $this->tempDir . '/di.xml';
        file_put_contents($diFile, $diXml);

        $files = [
            'php' => [$fileA, $fileB],
            'di' => [$diFile],
        ];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $ruleIds = array_column($report, 'ruleId');
        $this->assertNotContains(
            'deepPluginStack',
            $ruleIds,
            'Should not detect deep stack without generated directory'
        );
    }

    public function testNoDeepStackWithSinglePlugin(): void
    {
        $generatedDir = $this->tempDir . '/generated/code';
        $interceptorDir = $generatedDir . '/Magento/Catalog/Model/Product';
        mkdir($interceptorDir, 0777, true);

        $interceptorContent = <<<'PHP'
<?php
namespace Magento\Catalog\Model\Product;

class Interceptor extends \Magento\Catalog\Model\Product
{
    public function save()
    {
        return $this->___callPlugins('save', func_get_args(), $pluginInfo);
    }
}
PHP;
        file_put_contents($interceptorDir . '/Interceptor.php', $interceptorContent);
        Scanner::setGeneratedPath($generatedDir);

        $pluginA = <<<'PHP'
<?php
namespace Vendor\ModuleA\Plugin;

class ProductPlugin
{
    public function aroundSave($subject, callable $proceed)
    {
        $this->doSomething();
        return $proceed();
    }
}
PHP;

        $fileA = $this->tempDir . '/PluginA.php';
        file_put_contents($fileA, $pluginA);

        $diXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_a" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
    </type>
</config>
XML;

        $diFile = $this->tempDir . '/di.xml';
        file_put_contents($diFile, $diXml);

        $files = [
            'php' => [$fileA],
            'di' => [$diFile],
        ];

        $processor = new AroundPlugins();

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $ruleIds = array_column($report, 'ruleId');
        $this->assertNotContains(
            'deepPluginStack',
            $ruleIds,
            'Single plugin should not trigger deep stack warning'
        );
    }
}
