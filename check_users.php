<?php
/**
 * Temporary script to check actual users in database
 */
require_once 'config/database.php';
require_once 'config/app.php';

$db = Database::getInstance()->getConnection();

echo "=== GEBRUIKERS IN DATABASE ===\n\n";

$stmt = $db->query('SELECT id, tenant_id, email, role, first_name, last_name, created_at FROM users ORDER BY id');
$users = $stmt->fetchAll();

foreach ($users as $user) {
    echo "ID: {$user['id']}\n";
    echo "  Email: {$user['email']}\n";
    echo "  Rol: {$user['role']}\n";
    echo "  Naam: {$user['first_name']} {$user['last_name']}\n";
    echo "  Tenant ID: {$user['tenant_id']}\n";
    echo "  Created: {$user['created_at']}\n";
    echo "\n";
}

echo "=== TENANT INFO ===\n\n";
$stmt = $db->query('SELECT id, uuid, name, slug, brand_color, mollie_status FROM tenants ORDER BY id');
$tenants = $stmt->fetchAll();

foreach ($tenants as $tenant) {
    echo "ID: {$tenant['id']}\n";
    echo "  UUID: {$tenant['uuid']}\n";
    echo "  Naam: {$tenant['name']}\n";
    echo "  Slug: {$tenant['slug']}\n";
    echo "  Brand kleur: {$tenant['brand_color']}\n";
    echo "  Mollie status: {$tenant['mollie_status']}\n";
    echo "\n";
}

echo "=== WALLETS ===\n\n";
$stmt = $db->query('SELECT w.user_id, w.balance_cents, w.points_cents, u.email 
                     FROM wallets w 
                     JOIN users u ON w.user_id = u.id 
                     ORDER BY w.user_id');
$wallets = $stmt->fetchAll();

foreach ($wallets as $wallet) {
    echo "User {$wallet['user_id']} ({$wallet['email']}):\n";
    echo "  Saldo: EUR " . number_format($wallet['balance_cents'] / 100, 2) . "\n";
    echo "  Punten: " . number_format($wallet['points_cents'] / 100, 2) . "\n";
    echo "\n";
}
