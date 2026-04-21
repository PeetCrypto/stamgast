<?php
/**
 * STAMGAST - Test Script Fase 1 & 2
 * Run: http://stamgast.test/test_phase1_phase2.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Fase 1 & 2 - Stamgast</title>
    <style>
        body { background: #0f0f0f; color: #fff; font-family: sans-serif; padding: 20px; }
        h1 { color: #FFC107; }
        .test { padding: 10px; margin: 5px 0; border-radius: 4px; }
        .pass { background: rgba(76,175,80,0.2); border-left: 3px solid #4CAF50; }
        .fail { background: rgba(244,67,54,0.2); border-left: 3px solid #f44336; }
        pre { background: #222; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>STAMGAST - Test Fase 1 & 2</h1>';

$pass = 0;
$fail = 0;

// ============================================
// TEST FUNCTIONS
// ============================================

function test($name, $result) {
    global $pass, $fail;
    if ($result) {
        $pass++;
        echo "<div class='test pass'>PASS: $name</div>";
    } else {
        $fail++;
        echo "<div class='test fail'>FAIL: $name</div>";
    }
}

// ============================================
// DATABASE TESTS
// ============================================

echo '<h2>Database</h2>';

try {
    $pdo = new PDO('mysql:host=localhost;dbname=stamgast_db', 'root', '');
    test("Database connectie", true);
} catch (Exception $e) {
    test("Database connectie", false);
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants");
    $cnt = $stmt->fetchColumn();
    test("Tenants table ($cnt)", $cnt > 0);
} catch (Exception $e) {
    test("Tenants table", false);
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $cnt = $stmt->fetchColumn();
    test("Users table ($cnt)", $cnt > 0);
} catch (Exception $e) {
    test("Users table", false);
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM wallets");
    $cnt = $stmt->fetchColumn();
    test("Wallets table ($cnt)", $cnt > 0);
} catch (Exception $e) {
    test("Wallets table", false);
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM loyalty_tiers");
    $cnt = $stmt->fetchColumn();
    test("Loyalty tiers ($cnt)", $cnt > 0);
} catch (Exception $e) {
    test("Loyalty tiers", false);
}

// ============================================
// CONFIG FILES
// ============================================

echo '<h2>Config Files</h2>';

test("config/app.php", file_exists(__DIR__ . '/config/app.php'));
test("config/database.php", file_exists(__DIR__ . '/config/database.php'));
test("config/cors.php", file_exists(__DIR__ . '/config/cors.php'));

// ============================================
// MIDDLEWARE
// ============================================

echo '<h2>Middleware</h2>';

test("middleware/auth_check.php", file_exists(__DIR__ . '/middleware/auth_check.php'));
test("middleware/role_check.php", file_exists(__DIR__ . '/middleware/role_check.php'));
test("middleware/csrf.php", file_exists(__DIR__ . '/middleware/csrf.php'));

// ============================================
// MODELS
// ============================================

echo '<h2>Models</h2>';

test("models/User.php", file_exists(__DIR__ . '/models/User.php'));
test("models/Tenant.php", file_exists(__DIR__ . '/models/Tenant.php'));

// ============================================
// SERVICES
// ============================================

echo '<h2>Services</h2>';

test("services/AuthService.php", file_exists(__DIR__ . '/services/AuthService.php'));
test("services/QrService.php", file_exists(__DIR__ . '/services/QrService.php'));

// ============================================
// API
// ============================================

echo '<h2>API Endpoints</h2>';

test("api/auth/login.php", file_exists(__DIR__ . '/api/auth/login.php'));
test("api/auth/register.php", file_exists(__DIR__ . '/api/auth/register.php'));
test("api/auth/logout.php", file_exists(__DIR__ . '/api/auth/logout.php'));
test("api/auth/session.php", file_exists(__DIR__ . '/api/auth/session.php'));
test("api/superadmin/tenants.php", file_exists(__DIR__ . '/api/superadmin/tenants.php'));
test("api/superadmin/overview.php", file_exists(__DIR__ . '/api/superadmin/overview.php'));

// ============================================
// VIEWS
// ============================================

echo '<h2>Views</h2>';

test("views/shared/header.php", file_exists(__DIR__ . '/views/shared/header.php'));
test("views/shared/footer.php", file_exists(__DIR__ . '/views/shared/footer.php'));
test("views/shared/login.php", file_exists(__DIR__ . '/views/shared/login.php'));
test("views/shared/register.php", file_exists(__DIR__ . '/views/shared/register.php'));

// ============================================
// SUMMARY
// ============================================

echo "<h2>RESULT</h2>";
echo "<p>PASSED: $pass</p>";
echo "<p>FAILED: $fail</p>";

if ($fail == 0) {
    echo "<p style='color:#4CAF50;font-size:20px;'>ALL TESTS PASSED!</p>";
} else {
    echo "<p style='color:#f44336;'>Some tests failed</p>";
}

echo '</body></html>';