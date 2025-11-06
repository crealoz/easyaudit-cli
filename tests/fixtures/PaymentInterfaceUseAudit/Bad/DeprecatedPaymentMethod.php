<?php

namespace Vendor\Payment\Model\Method;

/**
 * Bad example: Extends deprecated AbstractMethod
 * This approach is no longer recommended in Magento 2
 */
class DeprecatedPaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'deprecated_payment';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // Old-style payment authorization
        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // Old-style payment capture
        return $this;
    }
}
