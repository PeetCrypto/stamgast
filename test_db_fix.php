<?php
// Test script to check if the database migration has been applied
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Test if we can access the created_at column
    $stmt = $db->prepare("SELECT `created_at` FROM `transactions` LIMIT 1");
    $stmt->execute();
    echo "SUCCESS: Database connection and created_at column test successful.\n";
    echo "The database fix has been applied.\n";
} catch (Exception $e) {
    $errorInfo = $e->getMessage();
    if (strpos($errorInfo, 'Unknown column') !== false) {
        echo "ERROR: The database migration has not been applied yet.\n";
        echo "Error: " . $errorInfo . "\n";
        echo "Please run the database migration script to fix this issue.\n";
    } else {
        echo "Database connection successful but other error occurred.\n";
        echo "Error: " . $errorInfo . "\n";
    }
}
?>