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
        // GOOD: getSize() uses COUNT SQL query
        return $this->collection->getSize() > 0;
    }

    public function getProductCount(): int
    {
        // GOOD: getSize() uses COUNT SQL query
        return $this->collection->getSize();
    }
}
