<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Types;
use PHPUnit\Framework\TestCase;

class TypesTest extends TestCase
{
    public function testIsCollectionTypeTrue(): void
    {
        $this->assertTrue(Types::isCollectionType('Magento\Catalog\Model\ResourceModel\Product\Collection'));
    }

    public function testIsCollectionTypeFalseForFactory(): void
    {
        $this->assertFalse(Types::isCollectionType('Magento\Catalog\Model\ResourceModel\Product\CollectionFactory'));
    }

    public function testIsCollectionTypeFalseForOther(): void
    {
        $this->assertFalse(Types::isCollectionType('Magento\Catalog\Model\Product'));
    }

    public function testIsCollectionFactoryTypeTrue(): void
    {
        $this->assertTrue(Types::isCollectionFactoryType('Magento\Catalog\Model\ResourceModel\Product\CollectionFactory'));
    }

    public function testIsCollectionFactoryTypeFalseForCollection(): void
    {
        $this->assertFalse(Types::isCollectionFactoryType('Magento\Catalog\Model\ResourceModel\Product\Collection'));
    }

    public function testIsRepositoryTrue(): void
    {
        $this->assertTrue(Types::isRepository('Magento\Catalog\Model\ProductRepository'));
    }

    public function testIsRepositoryFalse(): void
    {
        $this->assertFalse(Types::isRepository('Magento\Catalog\Model\Product'));
    }

    public function testIsResourceModelTrue(): void
    {
        $this->assertTrue(Types::isResourceModel('Magento\Catalog\Model\ResourceModel\Product'));
    }

    public function testIsResourceModelFalse(): void
    {
        $this->assertFalse(Types::isResourceModel('Magento\Catalog\Model\Product'));
    }

    public function testIsNonMagentoLibraryTrue(): void
    {
        $this->assertTrue(Types::isNonMagentoLibrary('GuzzleHttp\Client'));
        $this->assertTrue(Types::isNonMagentoLibrary('Psr\Log\LoggerInterface'));
        $this->assertTrue(Types::isNonMagentoLibrary('\\Symfony\\Component\\Console\\Command'));
    }

    public function testIsNonMagentoLibraryFalse(): void
    {
        $this->assertFalse(Types::isNonMagentoLibrary('Magento\Catalog\Model\Product'));
        $this->assertFalse(Types::isNonMagentoLibrary('Vendor\Module\Model\Custom'));
    }

    public function testMatchesSuffixTrue(): void
    {
        $this->assertTrue(Types::matchesSuffix('ProductInterface', ['Interface', 'Factory']));
        $this->assertTrue(Types::matchesSuffix('ProductFactory', ['Interface', 'Factory']));
    }

    public function testMatchesSuffixFalse(): void
    {
        $this->assertFalse(Types::matchesSuffix('ProductModel', ['Interface', 'Factory']));
    }

    public function testMatchesSubstringTrue(): void
    {
        $this->assertTrue(Types::matchesSubstring('Magento\Framework\App\Config', ['Framework']));
    }

    public function testMatchesSubstringFalse(): void
    {
        $this->assertFalse(Types::matchesSubstring('Vendor\Module\Model\Product', ['Framework']));
    }

    public function testHasApiInterfaceReturnsFalseForNonexistentClass(): void
    {
        $this->assertFalse(Types::hasApiInterface('Nonexistent\Class\That\Does\Not\Exist'));
    }

    public function testGetApiInterfaceFallback(): void
    {
        // For a nonexistent class, it should return the fallback pattern
        $result = Types::getApiInterface('Vendor\Module\Model\Product');
        $this->assertEquals('Vendor\Module\Api\Data\ProductInterface', $result);
    }
}
