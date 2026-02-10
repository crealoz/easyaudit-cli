<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Modules;
use PHPUnit\Framework\TestCase;

class ModulesTest extends TestCase
{
    public function testExtractModuleNameFromPathAppCode(): void
    {
        $path = '/var/www/magento/app/code/Vendor/Module/Model/Product.php';
        $this->assertEquals('Vendor_Module', Modules::extractModuleNameFromPath($path));
    }

    public function testExtractModuleNameFromPathVendor(): void
    {
        $path = '/var/www/magento/vendor/vendor-name/module-catalog/Model/Product.php';
        $this->assertEquals('VendorName_ModuleCatalog', Modules::extractModuleNameFromPath($path));
    }

    public function testExtractModuleNameFromPathMagento2Module(): void
    {
        $path = '/var/www/magento/vendor/magento/magento2-catalog/Model/Product.php';
        $this->assertEquals('Magento_Catalog', Modules::extractModuleNameFromPath($path));
    }

    public function testExtractModuleNameFromPathGeneric(): void
    {
        // app/code pattern: Vendor/Module before Block/ directory
        $path = '/app/code/Acme/Catalog/Block/Product.php';
        $this->assertEquals('Acme_Catalog', Modules::extractModuleNameFromPath($path));
    }

    public function testExtractModuleNameFromPathReturnsNull(): void
    {
        $path = '/tmp/random/file.php';
        $this->assertNull(Modules::extractModuleNameFromPath($path));
    }

    public function testGroupFilesByModule(): void
    {
        $files = [
            '/app/code/Vendor/ModuleA/Model/Product.php',
            '/app/code/Vendor/ModuleA/Block/Product.php',
            '/app/code/Vendor/ModuleB/Model/Order.php',
            '/tmp/random/file.php',
        ];

        $result = Modules::groupFilesByModule($files);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('Vendor_ModuleA', $result);
        $this->assertArrayHasKey('Vendor_ModuleB', $result);
        $this->assertCount(2, $result['Vendor_ModuleA']);
        $this->assertCount(1, $result['Vendor_ModuleB']);
    }

    public function testIsSameModuleTrue(): void
    {
        $this->assertTrue(Modules::isSameModule(
            'Vendor\Module\Plugin\MyPlugin',
            'Vendor\Module\Model\MyModel'
        ));
    }

    public function testIsSameModuleFalse(): void
    {
        $this->assertFalse(Modules::isSameModule(
            'VendorA\Module\Plugin\MyPlugin',
            'VendorB\Module\Model\MyModel'
        ));
    }

    public function testIsSameModuleDifferentModule(): void
    {
        $this->assertFalse(Modules::isSameModule(
            'Vendor\ModuleA\Plugin\MyPlugin',
            'Vendor\ModuleB\Model\MyModel'
        ));
    }

    public function testIsSameModuleShortNamespace(): void
    {
        // Classes with only one segment should return false
        $this->assertFalse(Modules::isSameModule('SingleSegment', 'AnotherSingle'));
    }

    public function testIsBlockFileTrue(): void
    {
        $this->assertTrue(Modules::isBlockFile('/app/code/Vendor/Module/Block/Product.php'));
    }

    public function testIsBlockFileFalse(): void
    {
        $this->assertFalse(Modules::isBlockFile('/app/code/Vendor/Module/Model/Product.php'));
    }

    public function testIsBlockFileSubdirectory(): void
    {
        // Files in Block subdirectories should NOT match (pattern requires direct child)
        $this->assertFalse(Modules::isBlockFile('/app/code/Vendor/Module/Block/Adminhtml/Product.php'));
    }

    public function testIsSetupDirectoryTrue(): void
    {
        $this->assertTrue(Modules::isSetupDirectory('/app/code/Vendor/Module/Setup/InstallData.php'));
    }

    public function testIsSetupDirectoryFalse(): void
    {
        $this->assertFalse(Modules::isSetupDirectory('/app/code/Vendor/Module/Model/Product.php'));
    }

    public function testFindDiXmlForFileReturnsNullWhenNotFound(): void
    {
        $this->assertNull(Modules::findDiXmlForFile('/nonexistent/path/File.php'));
    }

    public function testFindDiXmlForFileAppCodePattern(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_modules_test_' . uniqid();
        mkdir($tempDir . '/app/code/Vendor/Module/etc', 0777, true);
        mkdir($tempDir . '/app/code/Vendor/Module/Model', 0777, true);

        $diFile = $tempDir . '/app/code/Vendor/Module/etc/di.xml';
        file_put_contents($diFile, '<?xml version="1.0"?><config/>');

        $phpFile = $tempDir . '/app/code/Vendor/Module/Model/Product.php';
        file_put_contents($phpFile, '<?php // dummy');

        $result = Modules::findDiXmlForFile($phpFile);
        $this->assertEquals($diFile, $result);

        @unlink($phpFile);
        @unlink($diFile);
        @rmdir($tempDir . '/app/code/Vendor/Module/Model');
        @rmdir($tempDir . '/app/code/Vendor/Module/etc');
        @rmdir($tempDir . '/app/code/Vendor/Module');
        @rmdir($tempDir . '/app/code/Vendor');
        @rmdir($tempDir . '/app/code');
        @rmdir($tempDir . '/app');
        @rmdir($tempDir);
    }

    public function testFindDiXmlForFileFallbackWalkUp(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_modules_test_' . uniqid();
        mkdir($tempDir . '/SomeDir/etc', 0777, true);
        mkdir($tempDir . '/SomeDir/Model', 0777, true);

        $diFile = $tempDir . '/SomeDir/etc/di.xml';
        file_put_contents($diFile, '<?xml version="1.0"?><config/>');

        $phpFile = $tempDir . '/SomeDir/Model/Product.php';
        file_put_contents($phpFile, '<?php // dummy');

        $result = Modules::findDiXmlForFile($phpFile);
        $this->assertEquals($diFile, $result);

        @unlink($phpFile);
        @unlink($diFile);
        @rmdir($tempDir . '/SomeDir/Model');
        @rmdir($tempDir . '/SomeDir/etc');
        @rmdir($tempDir . '/SomeDir');
        @rmdir($tempDir);
    }
}
