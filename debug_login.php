<?php
/**
 * Debug Login - Show exactly what's happening
 */

// Load config to get PEPPER
require_once __DIR__ . '/config/app.php';

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";
echo "=== DEBUG LOGIN ===\n\n";

// Show PEPPER value
echo "PEPPER defined: " . (defined('APP_PEPPER') ? 'YES' : 'NO') . "\n";
if (defined('APP_PEPPER')) {
    echo "PEPPER value: '" . APP_PEPPER . "'\n";
    echo "PEPPER length: " . strlen(APP_PEPPER) . "\n\n";
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get stored hash
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ?");
    $stmt->execute(['admin@stamgast.nl']);
    $storedHash = $stmt->fetchColumn();
    
    echo "Stored hash: " . substr($storedHash, 0, 50) . "...\n\n";
    
    // Test password
    $testPassword = 'admin123' . APP_PEPPER;
    echo "Test password (with pepper): " . substr($testPassword, 0, 25) . "...\n";
    
    $verified = password_verify($testPassword, $storedHash);
    echo "Result: " . ($verified ? '✅ SUCCESS!' : '❌ FAILED!') . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";