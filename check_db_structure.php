<?php
// Check database structure
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check transactions table structure
    echo "=== Transactions Table Structure ===\n";
    $stmt = $db->query('DESCRIBE transactions');
    $transactionsStructure = $stmt->fetchAll();
    foreach ($transactionsStructure as $row) {
        echo $row['Field'] . ' ' . $row['Type'] . ' ' . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . ' ' . ($row['Key'] ?? '') . ' ' . ($row['Default'] ?? '') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}