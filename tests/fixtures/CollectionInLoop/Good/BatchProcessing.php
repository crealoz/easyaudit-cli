<?php

namespace Vendor\Module\Model;

class ProductBatchUpdater
{
    private $productRepository;
    private $searchCriteriaBuilder;

    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    public function updatePrices(array $productIds, float $multiplier): void
    {
        // GOOD: batch loading with getList
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->create();

        $products = $this->productRepository->getList($criteria)->getItems();

        foreach ($products as $product) {
            $product->setPrice($product->getPrice() * $multiplier);
            $this->productRepository->save($product);
        }
    }
}
