<?php

namespace Vendor\Module\Model;

class LegacyImporter
{
    public function importData(array $skus): void
    {
        $index = 0;
        while ($index < count($skus)) {
            // BAD: static load inside while loop
            $product = Product::load($skus[$index]);
            $product->setStatus(1);
            $product->save();
            $index++;
        }
    }

    public function getFirstItems(array $categoryIds): array
    {
        $results = [];
        foreach ($categoryIds as $catId) {
            // BAD: getFirstItem inside loop
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('category_id', $catId);
            $results[] = $collection->getFirstItem();
        }
        return $results;
    }
}
