<?php

namespace Test\Fixtures\UseOfObjectManager;

use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * BAD: Direct usage of ObjectManager
 * This should trigger an ERROR
 */
class BadObjectManagerUsage
{
    private $objectManager;

    public function __construct()
    {
        // Anti-pattern: Getting ObjectManager instance directly
        $this->objectManager = ObjectManager::getInstance();
    }

    /**
     * Anti-pattern: Using ObjectManager to create objects
     */
    public function getProduct($id)
    {
        // BAD: Using ObjectManager->create()
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        return $productRepository->getById($id);
    }

    /**
     * Anti-pattern: Using ObjectManager->get()
     */
    public function getLogger()
    {
        // BAD: Using ObjectManager->get() for service location
        return $this->objectManager->get(\Psr\Log\LoggerInterface::class);
    }
}
