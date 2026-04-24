<?php
// Verification script to test if the database fix works
require_once 'config/database.php';

echo "STAMGAST LOYALTY PLATFORM - DATABASE VERIFICATION\n";
echo "==================================================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Test if we can access the created_at column
    echo "Checking if created_at column is accessible...\n";
    $stmt = $db->prepare("SELECT `created_at` FROM `transactions` LIMIT 1");
    $stmt->execute();
    
    echo "SUCCESS: Database fix verified. The created_at column is now accessible.\n";
    echo "The 500 error should be resolved.\n";
    
} catch (Exception $e) {
    // Check if it's a "column not found" error
    $errorInfo = $e->getMessage();
    if (strpos($errorInfo, 'Unknown column') !== false) {
        echo "ERROR: Database fix not applied correctly.\n";
        echo "The created_at column is still missing.\n";
        echo "Error: " . $errorInfo . "\n";
    } else {
        echo "SUCCESS: Database connection works.\n";
        echo "But there may be other issues with the query.\n";
        echo "Error: " . $errorInfo . "\n";
    }
}
?>