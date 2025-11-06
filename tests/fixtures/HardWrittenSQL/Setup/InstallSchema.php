<?php

namespace Test\Fixtures\HardWrittenSQL\Setup;

/**
 * This file is in a Setup directory and should be EXCLUDED from SQL checks
 * Setup scripts legitimately use raw SQL for database schema changes
 */
class InstallSchema
{
    /**
     * These SQL queries should NOT be detected because we're in Setup/
     */
    public function install()
    {
        $connection = $this->getConnection();

        // Schema creation - legitimate use of SQL in Setup
        $sql = "CREATE TABLE IF NOT EXISTS customer_custom (
            entity_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            custom_attribute VARCHAR(255)
        )";
        $connection->query($sql);

        // Data migration - legitimate in Setup
        $sql = "INSERT INTO customer_custom (customer_id, custom_attribute)
                SELECT entity_id, 'default_value'
                FROM customer";
        $connection->query($sql);

        // Schema modification
        $sql = "ALTER TABLE customer_custom ADD INDEX idx_customer (customer_id)";
        $connection->query($sql);

        // Data cleanup
        $sql = "DELETE FROM customer_custom WHERE customer_id NOT IN (SELECT entity_id FROM customer)";
        $connection->query($sql);
    }

    private function getConnection()
    {
        return null;
    }
}
