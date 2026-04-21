<?php
/**
 * FIX: Reset admin password met CORRECTE pepper
 * Run: http://stamgast.test/fix_login.php
 */

// Load config FIRST - dit is cruciaal!
require_once __DIR__ . '/config/app.php';

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";
echo "=== FIX LOGIN ===\n\n";

echo "Stap 1: Pepper laden uit config...\n";
echo "APP_PEPPER = '" . APP_PEPPER . "'\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Stap 2: Nieuwe hash genereren voor 'admin123'...\n";
    
    // Generate hash met de CORRECTE pepper
    $plainPassword = 'admin123';
    $pepperedPassword = $plainPassword . APP_PEPPER;
    $newHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);
    
    echo "Plain password: admin123\n";
    echo "Peppered: " . substr($pepperedPassword, 0, 30) . "...\n";
    echo "New hash: " . substr($newHash, 0, 50) . "...\n\n";
    
    echo "Stap 3: Update database...\n";
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@stamgast.nl'");
    $stmt->execute([$newHash]);
    echo "✅ Database updated!\n\n";
    
    echo "Stap 4: Verificatie testen...\n";
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = 'admin@stamgast.nl'");
    $stmt->execute();
    $storedHash = $stmt->fetchColumn();
    
    $testPassword = 'admin123' . APP_PEPPER;
    $verified = password_verify($testPassword, $storedHash);
    
    if ($verified) {
        echo "✅ VERIFICATIE GESLAAGD!\n";
        echo "\n✅ LOGIN ZOU NU MOETEN WERKEN!\n";
        echo "\nGa naar: http://stamgast.test/login\n";
        echo "Email: admin@stamgast.nl\n";
        echo "Password: admin123\n";
    } else {
        echo "❌ VERIFICATIE GEFaald!\n";
        echo "Iets is misgegaan...\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";