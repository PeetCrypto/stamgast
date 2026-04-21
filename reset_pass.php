<?php
/**
 * Reset admin password - with ARGON2ID and pepper
 * Run: http://stamgast.test/reset_pass.php
 */

// Define pepper (must match config/app.php)
define('APP_PEPPER', 'change-this-to-a-random-string-in-production-32chars_min');

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to MySQL\n";
    
    // Create password hash WITH pepper using ARGON2ID (like the system expects)
    $plainPassword = 'admin123';
    $pepperedPassword = $plainPassword . APP_PEPPER;
    $hash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);
    
    echo "Generated ARGON2ID hash for: admin123 (with pepper)\n";
    echo "Hash starts with: " . substr($hash, 0, 30) . "...\n";
    
    // Update admin user
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@stamgast.nl'");
    $stmt->execute([$hash]);
    
    echo "Updated password for admin@stamgast.nl\n";
    echo "\n✅ TESTING...\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
    exit;
}

echo "</pre>";

// Now verify with the stored hash
$pdo2 = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
$stmt = $pdo2->prepare("SELECT password_hash FROM users WHERE email = 'admin@stamgast.nl'");
$stmt->execute();
$storedHash = $stmt->fetchColumn();

// Test verification
$testPassword = 'admin123' . APP_PEPPER;
$verified = password_verify($testPassword, $storedHash);

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";
if ($verified) {
    echo "✅ PASSWORD VERIFICATION: SUCCESS!\n";
    echo "\nNow go to: http://stamgast.test/login\n";
    echo "Email: admin@stamgast.nl\n";
    echo "Password: admin123\n";
} else {
    echo "❌ PASSWORD VERIFICATION: FAILED\n";
    echo "Stored hash: " . substr($storedHash, 0, 50) . "...\n";
}
echo "</pre>";