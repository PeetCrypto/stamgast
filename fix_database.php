<?php
// Add missing created_at column to transactions table
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if created_at column exists
    $stmt = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'created_at'");
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        // Add the created_at column to transactions table
        $db->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to transactions table\n";
    } else {
        echo "created_at column already exists in transactions table\n";
    }
    
    // Check other tables that should have created_at column
    echo "=== Checking and fixing all tables ===\n";
    
    // Check transactions table
    $stmt = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to transactions table\n";
    }
    
    // Check users table
    $stmt = $db->query("SHOW COLUMNS FROM `users` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to users table\n";
    }
    
    // Check tenants table
    $stmt = $db->query("SHOW COLUMNS FROM `tenants` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `tenants` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to tenants table\n";
    }
    
    // Check wallets table
    $stmt = $db->query("SHOW COLUMNS FROM `wallets` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `wallets` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to wallets table\n";
    }
    
    // Check loyalty_tiers table
    $stmt = $db->query("SHOW COLUMNS FROM `loyalty_tiers` LIKE 'created_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `loyalty_tiers` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added created_at column to loyalty_tiers table\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}