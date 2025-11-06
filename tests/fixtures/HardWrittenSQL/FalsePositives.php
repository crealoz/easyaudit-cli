<?php

namespace Test\Fixtures\HardWrittenSQL;

/**
 * This file contains patterns that should NOT be detected (false positives to avoid)
 */
class FalsePositives
{
    /**
     * SQL in single-line comments should be ignored
     */
    public function testCommentedSQL()
    {
        // This is a comment: SELECT * FROM customer WHERE id = 1
        // TODO: Implement proper repository: DELETE FROM customer
        $result = $this->repository->getById(1);
        return $result;
    }

    /**
     * SQL in multi-line comments should be ignored
     */
    public function testMultilineComments()
    {
        /*
         * Example SQL query that should not be detected:
         * SELECT * FROM customer
         * WHERE entity_id = 1
         *
         * DELETE FROM customer WHERE entity_id = 2
         */
        $result = $this->repository->getList();
        return $result;
    }

    /**
     * SQL in docblock examples should be ignored
     *
     * Example query:
     * SELECT c.* FROM customer c
     * JOIN customer_entity ce ON c.entity_id = ce.entity_id
     *
     * Or deletion:
     * DELETE FROM table WHERE id = 1
     */
    public function testDocblockSQL()
    {
        return $this->repository->save($entity);
    }

    /**
     * Magento's proper way - Repository pattern (should NOT trigger)
     */
    public function testProperRepository()
    {
        // Using repository pattern - this is correct!
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', 1, 'eq')
            ->create();

        $result = $this->customerRepository->getList($searchCriteria);
        return $result->getItems();
    }

    /**
     * Using collections properly (should NOT trigger)
     */
    public function testProperCollection()
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['eq' => 1]);
        $collection->getSelect()->join(
            ['ce' => 'customer_entity'],
            'main_table.entity_id = ce.entity_id',
            []
        );
        return $collection->getItems();
    }

    /**
     * String literals that just contain SQL keywords (edge case)
     */
    public function testStringLiterals()
    {
        $message = "Please SELECT an option";
        $error = "Failed to INSERT record";
        $info = "UPDATE available";
        return [$message, $error, $info];
    }
}
