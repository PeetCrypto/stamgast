<?php
// Database migration script to add missing created_at column
// This script should be run once to fix the database structure

header('Content-Type: text/plain; charset=utf-8');

echo "STAMGAST LOYALTY PLATFORM - DATABASE MIGRATION\n";
echo "===============================================\n\n";

try {
    // Database configuration
    $host = 'localhost';
    $dbname = 'stamgast_db';
    $username = 'root';
    $password = '';
    $charset = 'utf8mb4';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, $username, $password, $options);
    
    echo "Connected to database successfully.\n\n";
    
    // Check if created_at column exists in transactions table
    echo "Checking if created_at column exists in transactions table...\n";
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `transactions` LIKE 'created_at'");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        echo "Adding created_at column to transactions table...\n";
        $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "SUCCESS: Added created_at column to transactions table\n\n";
    } else {
        echo "created_at column already exists in transactions table\n\n";
    }
    
    // Also check and add created_at column to other tables
    $tables = ['users', 'tenants', 'wallets', 'loyalty_tiers', 'push_subscriptions', 'email_queue', 'audit_log'];
    
    foreach ($tables as $table) {
        echo "Checking if created_at column exists in $table table...\n";
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'created_at'");
        $stmt->execute();
        $columnExists = $stmt->fetch();
        
        if (!$columnExists) {
            echo "Adding created_at column to $table table...\n";
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "SUCCESS: Added created_at column to $table table\n\n";
        } else {
            echo "created_at column already exists in $table table\n\n";
        }
    }
    
    echo "=== DATABASE MIGRATION COMPLETE ===\n";
    echo "The 500 error should now be resolved.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Please check your database connection settings.\n";
}
?>