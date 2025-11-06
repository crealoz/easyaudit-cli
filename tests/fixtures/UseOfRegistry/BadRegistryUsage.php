<?php

namespace Test\Fixtures\UseOfRegistry;

use Magento\Framework\Registry;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * BAD: This class uses the deprecated Registry
 * This should trigger an ERROR
 */
class BadRegistryUsage
{
    /**
     * Constructor with Registry injection - BAD PRACTICE
     */
    public function __construct(
        private readonly Registry $registry,                          // ERROR: Registry usage
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Example of how Registry is typically (mis)used
     */
    public function getCurrentProduct()
    {
        // Anti-pattern: Using Registry to retrieve "current product"
        return $this->registry->registry('current_product');
    }

    /**
     * Another anti-pattern: Storing data in Registry
     */
    public function setCurrentProduct($product)
    {
        $this->registry->register('current_product', $product);
    }

    /**
     * Registry often used to pass data between disparate parts of the application
     */
    public function processOrder($orderId)
    {
        $order = $this->registry->registry('current_order');

        if (!$order) {
            $this->logger->error('No current order in registry');
            return false;
        }

        // Process order...
        return true;
    }
}
