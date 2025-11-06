<?php

namespace Test\Fixtures\SpecificClassInjection;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Session;

/**
 * Examples of BAD class injections that should trigger errors/warnings
 */
class BadInjections
{
    /**
     * ERROR: Collection injected directly - should use Factory
     * ERROR: Repository injected as concrete class - should use interface
     * ERROR: Resource Model injected - should use repository
     * WARNING: Model injected directly - might have interface
     */
    public function __construct(
        private readonly Collection $productCollection,          // ERROR: Collection must use Factory
        private readonly ProductRepository $productRepository,   // ERROR: Repository must use Interface
        private readonly ProductResource $productResource,       // ERROR: Resource Model should use Repository
        private readonly Product $product,                       // WARNING: Specific Model injection
        private readonly Customer $customer                      // WARNING: Specific Model injection
    ) {
    }

    public function someMethod()
    {
        // Using injected dependencies
        $products = $this->productCollection->getItems();
        $product = $this->productRepository->getById(1);

        return $products;
    }
}
