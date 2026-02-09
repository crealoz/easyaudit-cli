<?php

namespace Vendor\Module\Model;

class OrderProcessor
{
    private $orderRepository;
    private $searchCriteriaBuilder;

    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    public function processOrderIds(array $orderIds): void
    {
        // GOOD: load all orders at once before the loop
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $orderIds, 'in')
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        foreach ($orders as $order) {
            // Just iterating loaded items, no N+1
            echo $order->getIncrementId();
        }
    }
}
