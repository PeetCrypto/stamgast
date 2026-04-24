<?php
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting database migration...\n";
    
    // Check if created_at column already exists
    $stmt = $db->prepare("SHOW COLUMNS FROM `transactions` LIKE 'created_at'");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        // Add the missing created_at column to transactions table
        $db->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to transactions table\n";
    } else {
        echo "created_at column already exists in transactions table\n";
    }
    
    // Verify the fix works by running a simple query
    $stmt = $db->prepare("SELECT `created_at` FROM `transactions` LIMIT 1");
    $stmt->execute();
    echo "Database update completed successfully - created_at column is accessible\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Note: If the error is 'Duplicate column name', the column already exists\n";
}
?>