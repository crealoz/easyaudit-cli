<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Scanner;
use EasyAudit\Core\Scan\Util\Interceptor;
use PHPUnit\Framework\TestCase;

class InterceptorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_interceptor_' . uniqid();
        mkdir($this->tempDir . '/generated/code', 0777, true);
    }

    protected function tearDown(): void
    {
        Scanner::setGeneratedPath(null);
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

    public function testIsAvailableReturnsFalseWhenNoGeneratedPath(): void
    {
        Scanner::setGeneratedPath(null);
        $this->assertFalse(Interceptor::isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenGeneratedPathSet(): void
    {
        Scanner::setGeneratedPath($this->tempDir . '/generated/code');
        $this->assertTrue(Interceptor::isAvailable());
    }

    public function testGetInterceptorPathReturnsNullWhenNotAvailable(): void
    {
        Scanner::setGeneratedPath(null);
        $this->assertNull(Interceptor::getInterceptorPath('Vendor\Module\Model\Product'));
    }

    public function testGetInterceptorPathReturnsNullWhenFileDoesNotExist(): void
    {
        Scanner::setGeneratedPath($this->tempDir . '/generated/code');
        $this->assertNull(Interceptor::getInterceptorPath('Vendor\Module\Model\Product'));
    }

    public function testGetInterceptorPathReturnsPathWhenFileExists(): void
    {
        $generatedPath = $this->tempDir . '/generated/code';
        Scanner::setGeneratedPath($generatedPath);

        $interceptorDir = $generatedPath . '/Vendor/Module/Model/Product';
        mkdir($interceptorDir, 0777, true);
        $interceptorFile = $interceptorDir . '/Interceptor.php';
        file_put_contents($interceptorFile, '<?php // interceptor');

        $result = Interceptor::getInterceptorPath('Vendor\Module\Model\Product');
        $this->assertEquals($interceptorFile, $result);
    }

    public function testGetInterceptorPathHandlesLeadingBackslash(): void
    {
        $generatedPath = $this->tempDir . '/generated/code';
        Scanner::setGeneratedPath($generatedPath);

        $interceptorDir = $generatedPath . '/Vendor/Module/Model/Product';
        mkdir($interceptorDir, 0777, true);
        $interceptorFile = $interceptorDir . '/Interceptor.php';
        file_put_contents($interceptorFile, '<?php // interceptor');

        $result = Interceptor::getInterceptorPath('\Vendor\Module\Model\Product');
        $this->assertEquals($interceptorFile, $result);
    }

    public function testGetInterceptedMethodsExtractsCallPluginsMethods(): void
    {
        $interceptorContent = <<<'PHP'
<?php
namespace Vendor\Module\Model\Product;

class Interceptor extends \Vendor\Module\Model\Product
{
    public function save()
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'save');
        return $this->___callPlugins('save', func_get_args(), $pluginInfo);
    }

    public function getName()
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'getName');
        return $this->___callPlugins('getName', func_get_args(), $pluginInfo);
    }

    public function setName($name)
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'setName');
        return $this->___callPlugins('setName', func_get_args(), $pluginInfo);
    }
}
PHP;

        $file = $this->tempDir . '/Interceptor.php';
        file_put_contents($file, $interceptorContent);

        $methods = Interceptor::getInterceptedMethods($file);

        $this->assertContains('save', $methods);
        $this->assertContains('getName', $methods);
        $this->assertContains('setName', $methods);
        $this->assertCount(3, $methods);
    }

    public function testGetInterceptedMethodsReturnsEmptyForNonExistentFile(): void
    {
        $methods = Interceptor::getInterceptedMethods('/nonexistent/path/Interceptor.php');
        $this->assertEmpty($methods);
    }

    public function testGetInterceptedMethodsReturnsEmptyForFileWithoutCallPlugins(): void
    {
        $content = <<<'PHP'
<?php
class SomeClass
{
    public function normalMethod()
    {
        return 'nothing special';
    }
}
PHP;

        $file = $this->tempDir . '/NoPlugins.php';
        file_put_contents($file, $content);

        $methods = Interceptor::getInterceptedMethods($file);
        $this->assertEmpty($methods);
    }

    public function testGetInterceptedMethodsDeduplicates(): void
    {
        $content = <<<'PHP'
<?php
class Interceptor
{
    public function save()
    {
        $this->___callPlugins('save', func_get_args(), $pluginInfo);
        return $this->___callPlugins('save', func_get_args(), $pluginInfo);
    }
}
PHP;

        $file = $this->tempDir . '/Dedup.php';
        file_put_contents($file, $content);

        $methods = Interceptor::getInterceptedMethods($file);
        $this->assertCount(1, $methods);
        $this->assertContains('save', $methods);
    }
}
