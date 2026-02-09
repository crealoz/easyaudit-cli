<?php

namespace Vendor\Module\Model;

class ProductUpdater
{
    private $productRepository;

    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
    ) {
        $this->productRepository = $productRepository;
    }

    public function updatePrices(array $productIds, float $multiplier): void
    {
        for ($i = 0; $i < count($productIds); $i++) {
            // BAD: getById inside for loop
            $product = $this->productRepository->getById($productIds[$i]);
            $product->setPrice($product->getPrice() * $multiplier);
            $this->productRepository->save($product);
        }
    }
}
