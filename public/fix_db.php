<?php
// Fix database structure
header('Content-Type: text/plain');

try {
    require_once 'config/database.php';
    
    $db = Database::getInstance()->getConnection();
    
    echo "=== Database Structure Fix ===\n\n";
    
    // Check if created_at column exists in transactions table
    echo "Checking transactions table...\n";
    $stmt = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'created_at'");
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        echo "Adding created_at column to transactions table...\n";
        $db->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to transactions table\n\n";
    } else {
        echo "created_at column already exists in transactions table\n\n";
    }
    
    // Check other tables that should have created_at column
    echo "Checking all tables for created_at column...\n";
    
    // Check users table
    $stmt = $db->query("SHOW COLUMNS FROM `users` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        echo "Adding created_at column to users table...\n";
        $db->exec("ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to users table\n";
    } else {
        echo "users table already has created_at column\n";
    }
    
    // Check tenants table
    $stmt = $db->query("SHOW COLUMNS FROM `tenants` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        echo "Adding created_at column to tenants table...\n";
        $db->exec("ALTER TABLE `tenants` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to tenants table\n";
    } else {
        echo "tenants table already has created_at column\n";
    }
    
    // Check wallets table
    $stmt = $db->query("SHOW COLUMNS FROM `wallets` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        echo "Adding created_at column to wallets table...\n";
        $db->exec("ALTER TABLE `wallets` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to wallets table\n";
    } else {
        echo "wallets table already has created_at column\n";
    }
    
    // Check loyalty_tiers table
    $stmt = $db->query("SHOW COLUMNS FROM `loyalty_tiers` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        echo "Adding created_at column to loyalty_tiers table...\n";
        $db->exec("ALTER TABLE `loyalty_tiers` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to loyalty_tiers table\n";
    } else {
        echo "loyalty_tiers table already has created_at column\n";
    }
    
    echo "\n=== Database structure fix completed ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}