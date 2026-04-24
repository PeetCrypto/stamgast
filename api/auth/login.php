<?php
declare(strict_types=1);

/**
 * POST /api/auth/login
 * Authenticates a user with email and password
 * Superadmins: no tenant_id required (platform-level login)
 * Tenant users: tenant_id required, defaults to session or 1
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

// Determine tenant_id for tenant users
// If not provided, use default tenant 1
// Superadmins don't need a tenant_id — AuthService handles this
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

// Attempt login (AuthService handles superadmin vs tenant user internally)
$user = $authService->login($email, $password, $tenantId);

if ($user === null) {
    // Log failed attempt (use provided tenant_id, or null if unavailable)
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

// Start secure session (handles superadmin without tenant internally)
$authService->startSession($user);

// Log successful login — tenant_id is null for superadmins
$audit = new Audit($db);
$audit->log(
    $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
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
$redirect = BASE_URL . ($dashboardMap[$user['role']] ?? '/dashboard');

Response::success([
    'user' => [
        'id'         => (int) $user['id'],
        'role'       => $user['role'],
        'first_name' => $user['first_name'],
        'tenant_id'  => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
    ],
    'redirect' => $redirect,
]);
