<?php

namespace Vendor\Module\Block;

use Vendor\Module\Model\ResourceModel\Faq\Collection;
use Magento\Framework\View\Element\Template;

class LatestFaq extends Template
{
    public function __construct(
        Template\Context $context,
        private Collection $faqCollection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getLatestFaq()
    {
        return $this->faqCollection;
    }
}
