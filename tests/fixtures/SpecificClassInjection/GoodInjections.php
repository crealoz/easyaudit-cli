<?php

namespace Test\Fixtures\SpecificClassInjection;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Escaper;
use Psr\Log\LoggerInterface;

/**
 * Examples of GOOD class injections that should NOT trigger errors
 */
class GoodInjections
{
    /**
     * All these injections are correct and should not trigger any warnings
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,        // OK: Factory
        private readonly ProductRepositoryInterface $productRepository, // OK: Interface
        private readonly Context $context,                            // OK: Context (ignored)
        private readonly SerializerInterface $serializer,             // OK: Interface
        private readonly Escaper $escaper,                            // OK: Hardcoded ignored class
        private readonly LoggerInterface $logger                      // OK: Interface
    ) {
    }

    public function someMethod()
    {
        // Proper usage with Factory
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', 1);

        // Proper usage with Repository Interface
        $product = $this->productRepository->getById(1);

        return $collection->getItems();
    }
}
