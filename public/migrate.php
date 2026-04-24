<?php
// Database migration script to add missing created_at columns
// This script should be run once to fix the database structure

header('Content-Type: text/plain; charset=utf-8');

echo "STAMGAST LOYALTY PLATFORM - DATABASE MIGRATION\n";
echo "===============================================\n\n";

try {
    require_once __DIR__ . '/../config/database.php';
    
    $db = Database::getInstance()->getConnection();
    
    echo "Connected to database successfully.\n\n";
    
    // List of tables that should have created_at column
    $tables = [
        'transactions',
        'users', 
        'tenants',
        'wallets',
        'loyalty_tiers',
        'push_subscriptions',
        'email_queue',
        'audit_log'
    ];
    
    $updatedTables = 0;
    
    foreach ($tables as $table) {
        echo "Checking table: $table\n";
        
        // Check if created_at column exists
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE 'created_at'");
        $stmt->execute();
        $columnExists = $stmt->fetch();
        
        if (!$columnExists) {
            echo "  Adding created_at column to $table table...\n";
            
            // Add the created_at column
            $db->exec("ALTER TABLE `$table` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            
            // Also add updated_at column if it doesn't exist
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE 'updated_at'");
            $stmt->execute();
            $updatedExists = $stmt->fetch();
            
            if (!$updatedExists && in_array($table, ['users', 'tenants', 'wallets'])) {
                $db->exec("ALTER TABLE `$table` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                echo "  Added updated_at column to $table table.\n";
            }
            
            echo "  SUCCESS: Added created_at column to $table table\n\n";
            $updatedTables++;
        } else {
            echo "  created_at column already exists in $table table\n\n";
        }
    }
    
    echo "=== DATABASE MIGRATION COMPLETE ===\n";
    echo "Updated $updatedTables tables.\n";
    echo "The 500 error should now be resolved.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Please check your database connection settings.\n";
}
?>