<?php
/**
 * Debug - Exact copy of API /auth/login flow
 */

// Load config (exactly like index.php does)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/audit.php';
require_once __DIR__ . '/utils/validator.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = 'POST';

// This is EXACTLY what line 10 of api/auth/login.php does:
require_once __DIR__ . '/services/AuthService.php';

// Now mimic the login request (like JSON from JavaScript)
$input = [
    'email' => 'admin@stamgast.nl',
    'password' => 'admin123'
];

echo "<pre style='background:#222;color:#fff;padding:20px;'>";
echo "=== DEBUG JS LOGIN FLOW ===\n\n";

echo "1. method: $method\n";
echo "2. input: " . json_encode($input) . "\n\n";

// Get tenant_id (like api/auth/login.php does)
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$tenantId = isset($input['tenant_id']) ? (int) $input['tenant_id'] : null;

// Determine tenant_id - exact copy from api/auth/login.php line 29-33
if ($tenantId === null) {
    $tenantId = currentTenantId() ?? 1;
}

echo "3. determined tenant_id: $tenantId\n";
echo "4. email: $email\n";
echo "5. password: [HIDDEN]\n\n";

// Now test login
try {
    $db = Database::getInstance()->getConnection();
    $authService = new AuthService($db);
    
    echo "6. Calling AuthService->login()...\n";
    $result = $authService->login($email, $password, $tenantId);
    
    if ($result) {
        echo "✅ LOGIN SUCCESS!\n";
        echo "User ID: " . $result['id'] . "\n";
        echo "Email: " . $result['email'] . "\n";
        echo "Role: " . $result['role'] . "\n";
    } else {
        echo "❌ LOGIN FAILED - returned null\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";