<?php

namespace Vendor\GoodModule\Block;

class ProductList extends \Magento\Framework\View\Element\Template
{
    public function getViewModel()
    {
        return $this->getData('view_model');
    }
}
