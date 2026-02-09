<?php

namespace Vendor\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

class ProductCounter
{
    private Collection $collection;

    public function __construct(
        Collection $collection
    ) {
        $this->collection = $collection;
    }

    public function hasProducts(): bool
    {
        // BAD: count() loads all items into memory
        return count($this->collection) > 0;
    }

    public function getProductCount(): int
    {
        // BAD: count() loads all items into memory
        return count($this->collection);
    }
}
