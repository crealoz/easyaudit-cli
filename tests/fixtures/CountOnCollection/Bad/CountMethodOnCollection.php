<?php

namespace Vendor\Module\Model;

use Magento\Sales\Model\ResourceModel\Order\Collection;

class OrderAnalyzer
{
    private Collection $orderCollection;

    public function __construct(
        Collection $orderCollection
    ) {
        $this->orderCollection = $orderCollection;
    }

    public function getTotalOrders(): int
    {
        // BAD: ->count() loads all items into memory
        return $this->orderCollection->count();
    }
}
