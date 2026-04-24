<?php
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

echo "\n=== Sample Users Data ===\n";
$stmt = $db->query('SELECT id, email, role, tenant_id FROM users LIMIT 5');
$usersData = $stmt->fetchAll();
foreach ($usersData as $row) {
    echo "ID: {$row['id']}, Email: {$row['email']}, Role: {$row['role']}, Tenant ID: {$row['tenant_id']}\n";
}

echo "\n=== Sample Wallets Data ===\n";
$stmt = $db->query('SELECT id, user_id, tenant_id, balance_cents FROM wallets LIMIT 5');
$walletsData = $stmt->fetchAll();
foreach ($walletsData as $row) {
    echo "ID: {$row['id']}, User ID: {$row['user_id']}, Tenant ID: {$row['tenant_id']}, Balance: {$row['balance_cents']}\n";
}