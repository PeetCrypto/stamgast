<?php
/**
 * Debug - Mimic exact API login flow
 */

echo "<pre style='background:#222;color:#fff;padding:20px;'>";
echo "=== DEBUG API LOGIN FLOW ===\n\n";

// Step 1: Load config like index.php does
require_once __DIR__ . '/config/app.php';

echo "1. Loaded config/app.php\n";
echo "   APP_PEPPER defined: " . (defined('APP_PEPPER') ? 'YES' : 'NO') . "\n";
if (defined('APP_PEPPER')) {
    echo "   APP_PEPPER: '" . APP_PEPPER . "'\n";
}

// Step 2: Load Database like index.php does  
require_once __DIR__ . '/config/database.php';

echo "\n2. Loaded config/database.php\n";

// Step 3: Load AuthService like API does
require_once __DIR__ . '/services/AuthService.php';

echo "3. Loaded services/AuthService.php\n";

// Step 4: Try login
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $authService = new AuthService($pdo);
    
    echo "\n4. Testing login...\n";
    $result = $authService->login('admin@stamgast.nl', 'admin123', 1);
    
    if ($result) {
        echo "✅ LOGIN SUCCESS!\n";
        echo "User: " . $result['email'] . "\n";
        echo "Role: " . $result['role'] . "\n";
    } else {
        echo "❌ LOGIN FAILED!\n";
        echo "returned null\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";