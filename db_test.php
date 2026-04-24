<?php
// Simple database connection test
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "Database connection successful.\n";
    
    // Test if we can access the created_at column
    $stmt = $db->prepare("SELECT `created_at` FROM `transactions` LIMIT 1");
    $stmt->execute();
    echo "SUCCESS: Database connection and query test successful.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    // This is expected if the column doesn't exist
    echo "Now attempting to add the missing column...\n";
    
    // Try to add the missing column
    try {
        $db->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added missing created_at column to transactions table\n";
    } catch (Exception $e2) {
        echo "Error adding column: " . $e2->getMessage() . "\n";
    }
}
?>