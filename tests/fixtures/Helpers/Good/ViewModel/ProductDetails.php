<?php

namespace Vendor\Module\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Good example: ViewModel for presentation logic
 * Replaces helper usage in templates
 */
class ProductDetails implements ArgumentInterface
{
    public function __construct(
        private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getFormattedPrice(float $price): string
    {
        return '$' . number_format($price, 2);
    }

    public function isFeatureEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('module/general/enabled');
    }

    public function getCustomerName(): string
    {
        return 'John Doe';
    }
}
