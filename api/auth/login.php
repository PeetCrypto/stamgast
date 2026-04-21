<?php
declare(strict_types=1);

/**
 * POST /api/auth/login
 * Authenticates a user with email and password
 */

// Load services
require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../utils/audit.php';

// Only allow POST
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get JSON input
$input = getJsonInput();

$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$tenantId = isset($input['tenant_id']) ? (int) $input['tenant_id'] : null;

// Validate required fields
if (empty($email) || empty($password)) {
    Response::error('E-mail en wachtwoord zijn verplicht', 'MISSING_FIELDS', 400);
}

// Determine tenant_id
// If not provided, use default tenant 1
if ($tenantId === null) {
    $tenantId = currentTenantId() ?? 1;
}

// Validate email format
if (!isValidEmail($email)) {
    Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
}

// Load dependencies
$db = Database::getInstance()->getConnection();
$authService = new AuthService($db);

// Attempt login
$user = $authService->login($email, $password, $tenantId);

if ($user === null) {
    // Log failed attempt
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        null,
        'auth.login_failed',
        'user',
        null,
        ['email' => $email]
    );

    Response::error('Ongeldig e-mailadres of wachtwoord', 'INVALID_CREDENTIALS', 401);
}

// Start secure session
$authService->startSession($user);

// Log successful login
$audit = new Audit($db);
$audit->log(
    (int) $user['tenant_id'],
    (int) $user['id'],
    'auth.login_success',
    'user',
    (int) $user['id']
);

// Build role-based redirect URL
$dashboardMap = [
    'superadmin' => '/superadmin',
    'admin'      => '/admin',
    'bartender'  => '/scan',
    'guest'      => '/dashboard',
];
$redirect = $dashboardMap[$user['role']] ?? '/dashboard';

Response::success([
    'user' => [
        'id'         => (int) $user['id'],
        'role'       => $user['role'],
        'first_name' => $user['first_name'],
        'tenant_id'  => (int) $user['tenant_id'],
    ],
    'redirect' => $redirect,
]);
