<?php

namespace Test\Fixtures\UseOfObjectManager;

use Magento\Framework\ObjectManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * WARNING: ObjectManager imported but never used
 * This should trigger a WARNING for useless import
 */
class UselessImport
{
    /**
     * Proper dependency injection
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * This class doesn't use ObjectManager at all
     * The import is unnecessary
     */
    public function getProduct($id)
    {
        return $this->productRepository->getById($id);
    }

    public function logMessage($message)
    {
        $this->logger->info($message);
    }
}
