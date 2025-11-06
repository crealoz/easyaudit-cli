<?php

namespace Vendor\Payment\Model\Method;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * Bad example: Extends deprecated AbstractMethod (with use statement)
 */
class AnotherBadPayment extends AbstractMethod
{
    protected $_code = 'another_bad_payment';

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote);
    }
}
