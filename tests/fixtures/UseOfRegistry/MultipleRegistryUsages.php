<?php

namespace Test\Fixtures\UseOfRegistry;

use Magento\Framework\Registry;
use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * BAD: Multiple classes in one file using Registry
 * Both should trigger errors
 */
class MultipleRegistryUsages
{
    public function __construct(
        private readonly Registry $registry,  // ERROR: Registry usage
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function doSomething()
    {
        $data = $this->registry->registry('some_data');
        return $data;
    }
}

/**
 * Another class in the same file
 */
class AnotherClassWithRegistry
{
    private Registry $registry;  // ERROR: Registry usage

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function getData()
    {
        return $this->registry->registry('another_data');
    }
}
