<?php

namespace Test\Fixtures\UseOfRegistry;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Psr\Log\LoggerInterface;

/**
 * GOOD: Examples of proper alternatives to Registry
 * This should NOT trigger any errors
 */
class ProperAlternatives
{
    /**
     * Proper alternatives to Registry:
     * - Session classes for user-specific data
     * - DataPersistor for temporary data storage
     * - Explicit service injection
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly SessionManagerInterface $sessionManager,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Alternative 1: Use Session for customer-specific data
     */
    public function getCurrentCustomerId(): ?int
    {
        return $this->customerSession->getCustomerId();
    }

    /**
     * Alternative 2: Use DataPersistor for form data
     * Perfect replacement for Registry in form controllers
     */
    public function saveFormData(array $data): void
    {
        $this->dataPersistor->set('product_form_data', $data);
    }

    /**
     * Alternative 2: Retrieve persisted data
     */
    public function getFormData(): ?array
    {
        $data = $this->dataPersistor->get('product_form_data');
        $this->dataPersistor->clear('product_form_data');
        return $data;
    }

    /**
     * Alternative 3: Use SessionManager for generic session storage
     */
    public function storeTemporaryData(string $key, $value): void
    {
        $this->sessionManager->setData($key, $value);
    }

    /**
     * Alternative 3: Retrieve from session
     */
    public function getTemporaryData(string $key)
    {
        return $this->sessionManager->getData($key);
    }

    /**
     * Best Practice: Pass data explicitly as method parameters
     * instead of storing in global-like Registry
     */
    public function processData(array $explicitData): bool
    {
        $this->logger->info('Processing data with explicit parameters');

        // No hidden dependencies
        // Clear what data is being used
        // Easy to test

        return true;
    }
}
