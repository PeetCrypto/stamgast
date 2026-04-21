<?php
/**
 * Debug - Detailed login check
 */

echo "<pre style='background:#222;color:#fff;padding:20px;'>";
echo "=== DETAILED DEBUG ===\n\n";

// Load config
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/AuthService.php';
require_once __DIR__ . '/models/User.php';

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Find user directly
    echo "1. Finding user in database...\n";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND tenant_id = ?");
    $stmt->execute(['admin@stamgast.nl', 1]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dbUser) {
        echo "❌ User not found in DB!\n";
    } else {
        echo "✅ User found: ID=" . $dbUser['id'] . ", email=" . $dbUser['email'] . "\n";
        echo "   password_hash: " . substr($dbUser['password_hash'], 0, 30) . "...\n";
        
        // Step 2: Test password like AuthService does
        echo "\n2. Testing password...\n";
        
        // Get pepper the same way AuthService does
        $pepper = defined('APP_PEPPER') ? APP_PEPPER : '';
        echo "   Using pepper: '" . $pepper . "'\n";
        
        $testPassword = 'admin123' . $pepper;
        echo "   Test password (with pepper): " . substr($testPassword, 0, 25) . "...\n";
        
        // Verify
        $verified = password_verify($testPassword, $dbUser['password_hash']);
        echo "   password_verify result: " . ($verified ? '✅ TRUE' : '❌ FALSE') . "\n";
        
        // Let's also see what the hash actually starts with
        echo "\n3. Hash analysis:\n";
        echo "   Stored hash starts with: " . $dbUser['password_hash'] . "\n";
        echo "   Is Argon2id: " . (str_starts_with($dbUser['password_hash'], '$argon2id') ? 'YES' : 'NO') . "\n";
        echo "   Is Bcrypt: " . (str_starts_with($dbUser['password_hash'], '$2') ? 'YES' : 'NO') . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";