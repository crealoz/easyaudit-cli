<?php

namespace EasyAudit\Tests\Service;

use EasyAudit\Service\ClassToProxy;
use PHPUnit\Framework\TestCase;

class ClassToProxyTest extends TestCase
{
    public function testIsRequiredReturnsTrueForKnownHeavyClass(): void
    {
        $this->assertTrue(ClassToProxy::isRequired('Psr\Log\LoggerInterface'));
    }

    public function testIsRequiredReturnsTrueForMagentoClass(): void
    {
        $this->assertTrue(ClassToProxy::isRequired('Magento\Framework\App\ResourceConnection'));
    }

    public function testIsRequiredReturnsFalseForUnknownClass(): void
    {
        $this->assertFalse(ClassToProxy::isRequired('Vendor\Module\Model\SomeClass'));
    }

    public function testIsRequiredReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(ClassToProxy::isRequired(''));
    }

    public function testClassToProxyConstantIsNotEmpty(): void
    {
        $this->assertNotEmpty(ClassToProxy::CLASS_TO_PROXY);
    }
}
