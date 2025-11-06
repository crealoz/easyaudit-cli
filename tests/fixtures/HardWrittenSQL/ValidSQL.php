<?php

namespace Test\Fixtures\HardWrittenSQL;

/**
 * This file contains VALID SQL patterns that should be DETECTED
 */
class ValidSQL
{
    /**
     * Test case: SELECT query (should be ERROR)
     */
    public function testSelectQuery()
    {
        $connection = $this->getConnection();
        $sql = "SELECT * FROM customer WHERE entity_id = 1";
        return $connection->query($sql);
    }

    /**
     * Test case: DELETE query (should be ERROR)
     */
    public function testDeleteQuery()
    {
        $connection = $this->getConnection();
        $sql = "DELETE FROM customer WHERE entity_id = 1";
        return $connection->query($sql);
    }

    /**
     * Test case: INSERT query (should be WARNING)
     */
    public function testInsertQuery()
    {
        $connection = $this->getConnection();
        $sql = "INSERT INTO customer (firstname, lastname) VALUES ('John', 'Doe')";
        return $connection->query($sql);
    }

    /**
     * Test case: UPDATE query (should be WARNING)
     */
    public function testUpdateQuery()
    {
        $connection = $this->getConnection();
        $sql = "UPDATE customer SET firstname = 'Jane' WHERE entity_id = 1";
        return $connection->query($sql);
    }

    /**
     * Test case: JOIN query (should be NOTE)
     */
    public function testJoinQuery()
    {
        $connection = $this->getConnection();
        $sql = "SELECT c.* FROM customer c JOIN customer_entity ce ON c.entity_id = ce.entity_id";
        return $connection->query($sql);
    }

    /**
     * Test case: Case-insensitive detection - lowercase select
     */
    public function testLowercaseSelect()
    {
        $sql = "select * from customer";
        return $this->connection->query($sql);
    }

    /**
     * Test case: Case-insensitive detection - mixed case
     */
    public function testMixedCaseInsert()
    {
        $sql = "Insert Into customer (name) Values ('test')";
        return $this->connection->query($sql);
    }

    /**
     * Test case: Multi-line SQL query
     */
    public function testMultilineSQL()
    {
        $sql = "SELECT
                    c.entity_id,
                    c.firstname,
                    c.lastname
                FROM customer c
                WHERE c.entity_id = 1";
        return $this->connection->query($sql);
    }

    /**
     * Test case: Heredoc SQL query
     */
    public function testHeredocSQL()
    {
        $sql = <<<SQL
        SELECT * FROM customer
        WHERE entity_id = 1
        SQL;
        return $this->connection->query($sql);
    }

    /**
     * Test case: Concatenated SQL query
     */
    public function testConcatenatedSQL()
    {
        $table = 'customer';
        $sql = "SELECT * FROM " . $table . " WHERE entity_id = 1";
        return $this->connection->query($sql);
    }

    private function getConnection()
    {
        return null; // Mock
    }
}
