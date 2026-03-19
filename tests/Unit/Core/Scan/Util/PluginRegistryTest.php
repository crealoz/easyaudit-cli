<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\PluginRegistry;
use PHPUnit\Framework\TestCase;

class PluginRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        PluginRegistry::reset();
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_plugin_registry_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        PluginRegistry::reset();
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function createDiXml(string $content): string
    {
        $file = $this->tempDir . '/di_' . uniqid() . '.xml';
        file_put_contents($file, $content);
        return $file;
    }

    public function testBuildParsesPlugins(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="my_plugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $this->assertTrue(PluginRegistry::isBuilt());
        $this->assertEquals(
            'Magento\Catalog\Model\Product',
            PluginRegistry::getTargetClass('Vendor\Module\Plugin\ProductPlugin')
        );
    }

    public function testGetPluginsForTarget(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_a" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
        <plugin name="plugin_b" type="Vendor\ModuleB\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $this->assertCount(2, $plugins);
        $classes = array_column($plugins, 'class');
        $this->assertContains('Vendor\ModuleA\Plugin\ProductPlugin', $classes);
        $this->assertContains('Vendor\ModuleB\Plugin\ProductPlugin', $classes);
    }

    public function testDisabledPluginsExcludedFromGetPluginsForTarget(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="active_plugin" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
        <plugin name="disabled_plugin" type="Vendor\ModuleB\Plugin\ProductPlugin" disabled="true"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $this->assertCount(1, $plugins);
        $this->assertEquals('Vendor\ModuleA\Plugin\ProductPlugin', $plugins[0]['class']);
    }

    public function testDisabledPluginNotInTargetByPlugin(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="disabled_plugin" type="Vendor\Module\Plugin\Disabled" disabled="true"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $this->assertNull(PluginRegistry::getTargetClass('Vendor\Module\Plugin\Disabled'));
    }

    public function testResetClearsState(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="my_plugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);
        $this->assertTrue(PluginRegistry::isBuilt());

        PluginRegistry::reset();

        $this->assertFalse(PluginRegistry::isBuilt());
        $this->assertNull(PluginRegistry::getTargetClass('Vendor\Module\Plugin\ProductPlugin'));
        $this->assertEmpty(PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product'));
    }

    public function testBuildIsIdempotent(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="my_plugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);
        PluginRegistry::build([$diXml]); // Should not duplicate

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $this->assertCount(1, $plugins);
    }

    public function testGetTargetClassReturnsNullForUnknown(): void
    {
        PluginRegistry::build([]);
        $this->assertNull(PluginRegistry::getTargetClass('NonExistent\Class'));
    }

    public function testGetPluginsForTargetReturnsEmptyForUnknown(): void
    {
        PluginRegistry::build([]);
        $this->assertEmpty(PluginRegistry::getPluginsForTarget('NonExistent\Class'));
    }

    public function testMultipleDiXmlFiles(): void
    {
        $diXml1 = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_a" type="Vendor\ModuleA\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        $diXml2 = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="plugin_b" type="Vendor\ModuleB\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml1, $diXml2]);

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $this->assertCount(2, $plugins);
    }

    public function testPluginEntryContainsExpectedFields(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="my_plugin" type="Vendor\Module\Plugin\ProductPlugin"/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $plugin = $plugins[0];

        $this->assertArrayHasKey('class', $plugin);
        $this->assertArrayHasKey('name', $plugin);
        $this->assertArrayHasKey('disabled', $plugin);
        $this->assertArrayHasKey('diFile', $plugin);
        $this->assertEquals('Vendor\Module\Plugin\ProductPlugin', $plugin['class']);
        $this->assertEquals('my_plugin', $plugin['name']);
        $this->assertFalse($plugin['disabled']);
        $this->assertEquals($diXml, $plugin['diFile']);
    }

    public function testSkipsInvalidXml(): void
    {
        $invalidFile = $this->tempDir . '/invalid.xml';
        file_put_contents($invalidFile, 'not xml content');

        PluginRegistry::build([$invalidFile]);

        $this->assertTrue(PluginRegistry::isBuilt());
        $this->assertEmpty(PluginRegistry::getPluginsForTarget('anything'));
    }

    public function testSkipsEmptyPluginType(): void
    {
        $diXml = $this->createDiXml(<<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Catalog\Model\Product">
        <plugin name="empty_type" type=""/>
    </type>
</config>
XML);

        PluginRegistry::build([$diXml]);

        $plugins = PluginRegistry::getPluginsForTarget('Magento\Catalog\Model\Product');
        $this->assertEmpty($plugins);
    }
}
