<?php

namespace Vendor\Module\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

/**
 * Bad example: Helper extends AbstractHelper (deprecated pattern)
 */
class Data extends AbstractHelper
{
    public function getFormattedPrice($price)
    {
        return '$' . number_format($price, 2);
    }

    public function isFeatureEnabled()
    {
        return $this->scopeConfig->isSetFlag('module/general/enabled');
    }

    public function getCustomerName()
    {
        return 'John Doe';
    }
}
