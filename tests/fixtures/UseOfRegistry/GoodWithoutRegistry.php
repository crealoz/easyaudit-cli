<?php

namespace Test\Fixtures\UseOfRegistry;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GOOD: This class does NOT use Registry
 * This should NOT trigger any errors
 */
class GoodWithoutRegistry
{
    /**
     * Proper dependency injection without Registry
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Good practice: Explicit parameter passing
     */
    public function getCurrentProduct(): ?ProductInterface
    {
        $productId = $this->request->getParam('product_id');

        if (!$productId) {
            return null;
        }

        try {
            return $this->productRepository->getById($productId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load product: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Good practice: Method returns data explicitly
     */
    public function processProduct(ProductInterface $product): bool
    {
        // Process product with explicit parameter
        $this->logger->info('Processing product: ' . $product->getSku());

        // Business logic...

        return true;
    }

    /**
     * Good practice: Explicit service for shared state if needed
     */
    public function getProductFromRequest(): ?ProductInterface
    {
        // Instead of Registry, get data directly from request
        // or use proper service classes
        return $this->getCurrentProduct();
    }
}
