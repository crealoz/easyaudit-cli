<?php

namespace Vendor\Payment\Gateway;

/**
 * Good example: Payment gateway adapter
 * Modern approach using gateway commands and handlers
 */
class PaymentAdapter
{
    public function __construct(
        private \Magento\Payment\Gateway\Command\CommandPoolInterface $commandPool,
        private \Magento\Payment\Gateway\Validator\ValidatorPoolInterface $validatorPool
    ) {
    }

    public function authorize(array $buildSubject): array
    {
        return $this->commandPool->get('authorize')->execute($buildSubject);
    }

    public function capture(array $buildSubject): array
    {
        return $this->commandPool->get('capture')->execute($buildSubject);
    }
}
