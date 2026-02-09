<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\DiScope;
use PHPUnit\Framework\TestCase;

class DiScopeTest extends TestCase
{
    public function testGetScopeGlobal(): void
    {
        $this->assertEquals(DiScope::GLOBAL, DiScope::getScope('/app/code/Vendor/Module/etc/di.xml'));
    }

    public function testGetScopeFrontend(): void
    {
        $this->assertEquals(DiScope::FRONTEND, DiScope::getScope('/app/code/Vendor/Module/etc/frontend/di.xml'));
    }

    public function testGetScopeAdminhtml(): void
    {
        $this->assertEquals(DiScope::ADMINHTML, DiScope::getScope('/app/code/Vendor/Module/etc/adminhtml/di.xml'));
    }

    public function testGetScopeWebapiRest(): void
    {
        $this->assertEquals(DiScope::WEBAPI_REST, DiScope::getScope('/app/code/Vendor/Module/etc/webapi_rest/di.xml'));
    }

    public function testGetScopeCrontab(): void
    {
        $this->assertEquals(DiScope::CRONTAB, DiScope::getScope('/app/code/Vendor/Module/etc/crontab/di.xml'));
    }

    public function testGetScopeGraphql(): void
    {
        $this->assertEquals(DiScope::GRAPHQL, DiScope::getScope('/app/code/Vendor/Module/etc/graphql/di.xml'));
    }

    public function testIsGlobalTrue(): void
    {
        $this->assertTrue(DiScope::isGlobal('/app/code/Vendor/Module/etc/di.xml'));
    }

    public function testIsGlobalFalse(): void
    {
        $this->assertFalse(DiScope::isGlobal('/app/code/Vendor/Module/etc/frontend/di.xml'));
    }

    public function testDetectClassAreaAdminhtml(): void
    {
        $this->assertEquals('adminhtml', DiScope::detectClassArea('Magento\\Backend\\Block\\Adminhtml\\Dashboard'));
        $this->assertEquals('adminhtml', DiScope::detectClassArea('Vendor\\Module\\Controller\\Adminhtml\\Index'));
        $this->assertEquals('adminhtml', DiScope::detectClassArea('Vendor\\Module\\Ui\\Component\\Listing'));
    }

    public function testDetectClassAreaFrontend(): void
    {
        $this->assertEquals('frontend', DiScope::detectClassArea('Magento\\Catalog\\Block\\Product\\View'));
        $this->assertEquals('frontend', DiScope::detectClassArea('Vendor\\Module\\ViewModel\\ProductDetails'));
        $this->assertEquals('frontend', DiScope::detectClassArea('Vendor\\Module\\Controller\\Customer\\Account'));
        $this->assertEquals('frontend', DiScope::detectClassArea('Vendor\\Module\\Controller\\Checkout\\Index'));
    }

    public function testDetectClassAreaNull(): void
    {
        $this->assertNull(DiScope::detectClassArea('Magento\\Catalog\\Api\\ProductRepositoryInterface'));
        $this->assertNull(DiScope::detectClassArea('Vendor\\Module\\Model\\Service'));
        $this->assertNull(DiScope::detectClassArea('Vendor\\Module\\Helper\\Data'));
    }

    public function testLoadXmlValidFile(): void
    {
        $fixture = dirname(__DIR__, 4) . '/fixtures/Preferences/SinglePreferences_di.xml';
        $result = DiScope::loadXml($fixture);
        $this->assertInstanceOf(\SimpleXMLElement::class, $result);
    }

    public function testLoadXmlInvalidFile(): void
    {
        $result = DiScope::loadXml('/nonexistent/file.xml');
        $this->assertFalse($result);
    }
}
