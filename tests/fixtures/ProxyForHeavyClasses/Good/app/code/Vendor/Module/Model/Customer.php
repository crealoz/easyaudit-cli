<?php

namespace Vendor\Module\Model;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Good example: Session injected with proxy configuration in di.xml
 */
class Customer
{
    public function __construct(
        private Session $customerSession,
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getConfig(): mixed
    {
        return $this->scopeConfig->getValue('customer/config/path');
    }
}
