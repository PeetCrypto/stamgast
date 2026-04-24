<?php
// Simple database schema check
require_once 'config/database.php';

global $db;

// Check users table structure
echo "=== Users Table Structure ===\n";
$stmt = $db->query('DESCRIBE users');
$usersStructure = $stmt->fetchAll();
foreach ($usersStructure as $row) {
    echo $row['Field'] . ' ' . $row['Type'] . ' ' . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . ' ' . ($row['Key'] ?? '') . ' ' . ($row['Default'] ?? '') . "\n";
}

echo "\n=== Wallets Table Structure ===\n";
$stmt = $db->query('DESCRIBE wallets');
$walletsStructure = $stmt->fetchAll();
foreach ($walletsStructure as $row) {
    echo $row['Field'] . ' ' . $row['Type'] . ' ' . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . ' ' . ($row['Key'] ?? '') . ' ' . ($row['Default'] ?? '') . "\n";
}
?>