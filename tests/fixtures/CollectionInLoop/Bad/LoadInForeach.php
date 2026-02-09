<?php

namespace Vendor\Module\Model;

class OrderProcessor
{
    private $orderRepository;
    private $logger;

    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function processOrderIds(array $orderIds): void
    {
        foreach ($orderIds as $orderId) {
            // BAD: loading model inside foreach
            $order = $this->orderRepository->get($orderId);
            $order->load($orderId);
            $this->logger->info('Processing order: ' . $order->getIncrementId());
        }
    }
}
