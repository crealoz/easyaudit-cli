<?php

namespace Test\Fixtures\UseOfObjectManager;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Psr\Log\LoggerInterface;

/**
 * GOOD: Proper dependency injection without ObjectManager
 * This should NOT trigger any errors
 */
class GoodDependencyInjection
{
    /**
     * Proper constructor injection
     * No ObjectManager usage
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Using injected repository
     */
    public function getProduct($id)
    {
        try {
            return $this->productRepository->getById($id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load product: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Using injected factory
     */
    public function createProduct(array $data)
    {
        $product = $this->productFactory->create();
        $product->setData($data);
        return $product;
    }

    /**
     * Proper business logic with injected dependencies
     */
    public function processProducts(array $productIds)
    {
        $results = [];

        foreach ($productIds as $id) {
            $product = $this->getProduct($id);
            if ($product) {
                $results[] = $product;
            }
        }

        return $results;
    }
}
