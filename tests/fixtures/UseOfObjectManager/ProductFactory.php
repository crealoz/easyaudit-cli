<?php

namespace Test\Fixtures\UseOfObjectManager;

use Magento\Framework\ObjectManagerInterface;

/**
 * GOOD: Factory classes are allowed to use ObjectManager
 * This should NOT trigger any errors
 */
class ProductFactory
{
    /**
     * Factory classes are the legitimate use case for ObjectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Factories use ObjectManager to create instances
     * This is the correct pattern in Magento 2
     */
    public function create(array $data = [])
    {
        return $this->objectManager->create(
            \Magento\Catalog\Model\Product::class,
            ['data' => $data]
        );
    }
}
