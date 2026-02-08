<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Classes;
use PHPUnit\Framework\TestCase;

class ClassesTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static state between tests
        $ref = new \ReflectionClass(Classes::class);

        $hierarchy = $ref->getProperty('hierarchy');
        $hierarchy->setAccessible(true);
        $hierarchy->setValue(null, ['children' => [], 'classToFile' => []]);

        $processedFiles = $ref->getProperty('processedFiles');
        $processedFiles->setAccessible(true);
        $processedFiles->setValue(null, []);
    }

    // --- parseImportedClasses() ---

    public function testParseImportedClassesWithUseStatements(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Module;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\Product;
PHP;
        $result = Classes::parseImportedClasses($content);

        $this->assertArrayHasKey('ScopeConfigInterface', $result);
        $this->assertEquals('Magento\Framework\App\Config\ScopeConfigInterface', $result['ScopeConfigInterface']);
        $this->assertArrayHasKey('Product', $result);
        $this->assertEquals('Magento\Catalog\Model\Product', $result['Product']);
    }

    public function testParseImportedClassesWithAlias(): void
    {
        $content = <<<'PHP'
<?php
use Magento\Framework\Registry as CoreRegistry;
PHP;
        $result = Classes::parseImportedClasses($content);

        $this->assertArrayHasKey('CoreRegistry', $result);
        $this->assertEquals('Magento\Framework\Registry', $result['CoreRegistry']);
    }

    public function testParseImportedClassesNoImports(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Module;

class MyClass {}
PHP;
        $result = Classes::parseImportedClasses($content);
        $this->assertEmpty($result);
    }

    // --- hasImportedClass() / hasImportedClasses() ---

    public function testHasImportedClassTrue(): void
    {
        $content = "<?php\nuse Magento\\Framework\\Registry;";
        $this->assertTrue(Classes::hasImportedClass('Magento\Framework\Registry', $content));
    }

    public function testHasImportedClassFalse(): void
    {
        $content = "<?php\nuse Magento\\Catalog\\Model\\Product;";
        $this->assertFalse(Classes::hasImportedClass('Magento\Framework\Registry', $content));
    }

    public function testHasImportedClassesReturnsTrue(): void
    {
        $content = "<?php\nuse Magento\\Framework\\Registry;";
        $this->assertTrue(Classes::hasImportedClasses([
            'Magento\Framework\Registry',
            'Magento\Framework\Other',
        ], $content));
    }

    public function testHasImportedClassesReturnsFalse(): void
    {
        $content = "<?php\nuse Magento\\Catalog\\Model\\Product;";
        $this->assertFalse(Classes::hasImportedClasses([
            'Magento\Framework\Registry',
            'Magento\Framework\Other',
        ], $content));
    }

    // --- parseConstructorParameters() ---

    public function testParseConstructorParametersWithConstructor(): void
    {
        $content = <<<'PHP'
<?php
class MyClass
{
    public function __construct(ScopeConfigInterface $scopeConfig, LoggerInterface $logger)
    {
    }
}
PHP;
        $result = Classes::parseConstructorParameters($content);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('ScopeConfigInterface', $result[0]);
        $this->assertStringContainsString('LoggerInterface', $result[1]);
    }

    public function testParseConstructorParametersWithoutConstructor(): void
    {
        $content = <<<'PHP'
<?php
class MyClass
{
    public function execute(): void {}
}
PHP;
        $result = Classes::parseConstructorParameters($content);
        $this->assertEmpty($result);
    }

    public function testParseConstructorParametersWithPromotedProperties(): void
    {
        $content = <<<'PHP'
<?php
class MyClass
{
    public function __construct(private ScopeConfigInterface $scopeConfig, protected LoggerInterface $logger)
    {
    }
}
PHP;
        $result = Classes::parseConstructorParameters($content);
        $this->assertCount(2, $result);
    }

    // --- extractClassName() ---

    public function testExtractClassNameWithNamespace(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Module\Model;

class MyClass
{
}
PHP;
        $result = Classes::extractClassName($content);
        $this->assertEquals('Vendor\Module\Model\MyClass', $result);
    }

    public function testExtractClassNameWithoutNamespace(): void
    {
        $content = '<?php class OrphanClass {}';
        $result = Classes::extractClassName($content);
        $this->assertEquals('UnknownClass', $result);
    }

    // --- consolidateParameters() ---

    public function testConsolidateParametersSkipsBasicTypes(): void
    {
        $params = ['string $name', 'int $age', 'bool $active'];
        $result = Classes::consolidateParameters($params, []);
        $this->assertEmpty($result);
    }

    public function testConsolidateParametersResolvesImported(): void
    {
        $params = ['ScopeConfigInterface $scopeConfig'];
        $imports = ['ScopeConfigInterface' => 'Magento\Framework\App\Config\ScopeConfigInterface'];
        $result = Classes::consolidateParameters($params, $imports);

        $this->assertArrayHasKey('$scopeConfig', $result);
        $this->assertEquals('\Magento\Framework\App\Config\ScopeConfigInterface', $result['$scopeConfig']);
    }

    public function testConsolidateParametersWithPromotedAndVisibility(): void
    {
        $params = ['private ScopeConfigInterface $scopeConfig'];
        $imports = ['ScopeConfigInterface' => 'Magento\Framework\App\Config\ScopeConfigInterface'];
        $result = Classes::consolidateParameters($params, $imports);

        $this->assertArrayHasKey('$scopeConfig', $result);
    }

    public function testConsolidateParametersWithUnknownClass(): void
    {
        $params = ['SomeClass $obj'];
        $result = Classes::consolidateParameters($params, []);

        $this->assertArrayHasKey('$obj', $result);
        $this->assertEquals('SomeClass', $result['$obj']);
    }

    // --- resolveShortClassName() ---

    public function testResolveShortClassNameFqcn(): void
    {
        $result = Classes::resolveShortClassName(
            'Magento\Catalog\Model\Product',
            '',
            'Vendor\Module'
        );
        $this->assertEquals('Magento\Catalog\Model\Product', $result);
    }

    public function testResolveShortClassNameFromUseStatement(): void
    {
        $content = "<?php\nuse Magento\\Catalog\\Model\\Product;";
        $result = Classes::resolveShortClassName('Product', $content, 'Vendor\Module');
        $this->assertEquals('Magento\Catalog\Model\Product', $result);
    }

    public function testResolveShortClassNameFallbackToNamespace(): void
    {
        $content = "<?php\nnamespace Vendor\\Module;";
        $result = Classes::resolveShortClassName('MyHelper', $content, 'Vendor\Module');
        $this->assertEquals('Vendor\Module\MyHelper', $result);
    }

    // --- getParentConstructorParams() ---

    public function testGetParentConstructorParamsFound(): void
    {
        $content = <<<'PHP'
<?php
class Child extends Parent
{
    public function __construct($foo, $bar)
    {
        parent::__construct($foo, $bar);
    }
}
PHP;
        $result = Classes::getParentConstructorParams($content);
        $this->assertEquals(['foo', 'bar'], $result);
    }

    public function testGetParentConstructorParamsNotFound(): void
    {
        $content = <<<'PHP'
<?php
class Child
{
    public function __construct($foo) {}
}
PHP;
        $result = Classes::getParentConstructorParams($content);
        $this->assertEmpty($result);
    }

    // --- getConstructorParameterTypes() ---

    public function testGetConstructorParameterTypes(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Module;

use Magento\Framework\App\Config\ScopeConfigInterface;

class MyClass
{
    public function __construct(ScopeConfigInterface $scopeConfig, string $name)
    {
    }
}
PHP;
        $result = Classes::getConstructorParameterTypes($content);

        $this->assertArrayHasKey('$scopeConfig', $result);
        $this->assertArrayNotHasKey('$name', $result); // basic type skipped
    }

    // --- buildClassHierarchy() and getChildren() ---

    public function testBuildClassHierarchyAndGetChildren(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_classes_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $parentContent = <<<'PHP'
<?php
namespace Test\Module\Model;

class ParentModel
{
}
PHP;
        $childContent = <<<'PHP'
<?php
namespace Test\Module\Model;

class ChildModel extends ParentModel
{
}
PHP;
        file_put_contents($tempDir . '/ParentModel.php', $parentContent);
        file_put_contents($tempDir . '/ChildModel.php', $childContent);

        Classes::buildClassHierarchy([
            $tempDir . '/ParentModel.php',
            $tempDir . '/ChildModel.php',
        ]);

        $children = Classes::getChildren('Test\Module\Model\ParentModel');
        $this->assertContains('Test\Module\Model\ChildModel', $children);

        unlink($tempDir . '/ParentModel.php');
        unlink($tempDir . '/ChildModel.php');
        rmdir($tempDir);
    }

    public function testGetChildrenThrowsWhenNoChildren(): void
    {
        $this->expectException(\EasyAudit\Exception\Scanner\NoChildrenException::class);
        Classes::getChildren('NonExistent\Class\Name');
    }

    public function testBuildClassHierarchySkipsNonExtendingClasses(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_classes_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module;

class StandaloneClass
{
    public function doSomething(): void {}
}
PHP;
        file_put_contents($tempDir . '/StandaloneClass.php', $content);

        Classes::buildClassHierarchy([$tempDir . '/StandaloneClass.php']);

        // Should not throw, standalone class has no parent-child relationship
        $this->expectException(\EasyAudit\Exception\Scanner\NoChildrenException::class);
        Classes::getChildren('Test\Module\StandaloneClass');

        unlink($tempDir . '/StandaloneClass.php');
        rmdir($tempDir);
    }

    public function testBuildClassHierarchySkipsDuplicateFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_classes_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module;

class Child extends Parent
{
}
PHP;
        $file = $tempDir . '/Child.php';
        file_put_contents($file, $content);

        // Process the same file twice - should not error
        Classes::buildClassHierarchy([$file, $file]);
        $this->assertTrue(true);

        unlink($file);
        rmdir($tempDir);
    }

    // --- getInstantiation() ---

    public function testGetInstantiationPromotedProperty(): void
    {
        $content = <<<'PHP'
<?php
class MyClass
{
    public function __construct(private SomeClass $service)
    {
    }
}
PHP;
        $params = ['private SomeClass $service'];
        $result = Classes::getInstantiation($params, '$service', $content);
        $this->assertEquals('$this->service', $result);
    }

    public function testGetInstantiationAssignedProperty(): void
    {
        $content = <<<'PHP'
<?php
class MyClass
{
    private $myService;
    public function __construct(SomeClass $service)
    {
        $this->myService = $service;
    }
}
PHP;
        $params = ['SomeClass $service'];
        $result = Classes::getInstantiation($params, '$service', $content);
        $this->assertEquals('$this->myService', $result);
    }

    public function testGetInstantiationReturnsNullWhenNotFound(): void
    {
        $content = '<?php class A { public function __construct(B $b) {} }';
        $params = ['B $b'];
        $result = Classes::getInstantiation($params, '$other', $content);
        $this->assertNull($result);
    }

    public function testGetInstantiationThrowsWhenNotAssigned(): void
    {
        $this->expectException(\EasyAudit\Exception\Scanner\InstantiationNotFoundException::class);

        $content = <<<'PHP'
<?php
class MyClass
{
    public function __construct(SomeClass $service)
    {
        // $service is not assigned anywhere
    }
}
PHP;
        $params = ['SomeClass $service'];
        Classes::getInstantiation($params, '$service', $content);
    }
}
