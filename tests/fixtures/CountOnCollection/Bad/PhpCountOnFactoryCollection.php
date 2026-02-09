<?php

namespace Vendor\Module\Block;

use Vendor\Module\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\View\Element\Template;

class OrderCount extends Template
{
    public function __construct(
        Template\Context $context,
        private CollectionFactory $orderCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrderCount(): int
    {
        $orders = $this->orderCollectionFactory->create();
        $orders->addFieldToFilter('status', 'complete');
        return count($orders);
    }
}
