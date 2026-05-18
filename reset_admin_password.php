<?php
/**
 * Reset Superadmin Password Script
 * Run once, then DELETE this file
 */
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$pepper = defined('APP_PEPPER') ? APP_PEPPER : '';

$password = 'Admin123!';
$hash = password_hash($password . $pepper, PASSWORD_ARGON2ID);

$stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@regulr.vip' AND role = 'superadmin'");
$result = $stmt->execute([$hash]);

echo "Password reset: " . ($result ? "SUCCESS" : "FAILED") . "\n";
echo "Hash: " . substr($hash, 0, 50) . "...\n";
echo "Pepper: " . substr($pepper, 0, 20) . "...\n";

@unlink(__FILE__);