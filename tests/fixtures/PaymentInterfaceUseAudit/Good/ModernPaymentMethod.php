<?php

namespace Vendor\Payment\Model;

use Magento\Payment\Api\Data\PaymentMethodInterface;

/**
 * Good example: Modern payment method implementation
 * Uses PaymentMethodInterface instead of deprecated AbstractMethod
 */
class ModernPaymentMethod implements PaymentMethodInterface
{
    public function getCode()
    {
        return 'modern_payment';
    }

    public function getTitle()
    {
        return 'Modern Payment Method';
    }

    public function getFormBlockType()
    {
        return \Magento\Payment\Block\Form::class;
    }

    public function getInfoBlockType()
    {
        return \Magento\Payment\Block\Info::class;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return true;
    }

    public function isActive($storeId = null)
    {
        return true;
    }
}
