<?php

namespace Test\Fixtures\SpecificClassInjection;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Session\SessionManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mixed good and bad injections
 */
class MixedInjections
{
    public function __construct(
        // GOOD injections
        private readonly ProductRepositoryInterface $productRepository,  // OK: Interface
        private readonly CollectionFactory $collectionFactory,           // OK: Factory
        private readonly Context $context,                               // OK: Context
        private readonly LoggerInterface $logger,                        // OK: Interface
        private readonly SessionManagerInterface $sessionManager,        // OK: Interface

        // BAD injections
        private readonly Product $product,                               // WARNING: Specific Model
        private readonly CustomerResource $customerResource              // ERROR: Resource Model
    ) {
    }

    public function processProduct(int $id)
    {
        // Good practices
        $product = $this->productRepository->getById($id);
        $collection = $this->collectionFactory->create();

        // Bad practice - using injected model
        $directProduct = $this->product;

        return $product;
    }
}
