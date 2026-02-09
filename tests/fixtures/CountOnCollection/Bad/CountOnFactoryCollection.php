<?php

namespace Vendor\Module\Block;

use Vendor\Module\Model\ResourceModel\Faq\CollectionFactory;
use Magento\Framework\View\Element\Template;

class LatestFaq extends Template
{
    public function __construct(
        Template\Context $context,
        private CollectionFactory $faqCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFaqCount(): int
    {
        $collection = $this->faqCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        return $collection->count();
    }
}
