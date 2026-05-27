<?php
declare(strict_types=1);

/**
 * POST /api/auth/login
 * Authenticates a user with email and password
 *
 * TENANT ISOLATION:
 * - Superadmins: login via /login (no tenant context needed)
 * - Tenant users (admin, bartender, guest): login via /j/{slug} ONLY
 *   The tenant_slug is required — without it, only superadmins can login.
 */

// Load services
require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../utils/audit.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get JSON input
$input = getJsonInput();

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$tenantId = isset($input['tenant_id']) ? (int) $input['tenant_id'] : null;

// Resolve tenant_slug to tenant_id (login via /j/{slug})
$tenantSlug = trim($input['tenant_slug'] ?? '');
if (!empty($tenantSlug) && $tenantId === null) {
    require_once __DIR__ . '/../../models/Tenant.php';
    $db = Database::getInstance()->getConnection();
    $tenantBySlug = (new Tenant($db))->findBySlug($tenantSlug);
    if ($tenantBySlug && (bool) $tenantBySlug['is_active']) {
        $tenantId = (int) $tenantBySlug['id'];
    }
}

// Validate required fields
if (empty($email) || empty($password)) {
    Response::error('E-mail en wachtwoord zijn verplicht', 'MISSING_FIELDS', 400);
}

// Validate email format
if (!isValidEmail($email)) {
    Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
}

// ─────────────────────────────────────────────────────────────
// TENANT ISOLATION ENFORCEMENT
// ─────────────────────────────────────────────────────────────
// When NO tenant context is provided (login via /login, no slug),
// this is a PLATFORM-LEVEL login attempt — ONLY superadmins allowed.
// Tenant users (admin, bartender, guest) MUST login via /j/{slug}.
if ($tenantId === null) {
    // No tenant context — check if this is a superadmin
    require_once __DIR__ . '/../../models/User.php';
    $db = Database::getInstance()->getConnection();
    $userLookup = (new User($db))->findByEmailGlobal($email);

    if ($userLookup === null || $userLookup['role'] !== 'superadmin') {
        // Not a superadmin — tenant users must login via /j/{slug}
        // If the user exists, tell them which slug to use
        $hintSlug = null;
        if ($userLookup && $userLookup['tenant_id'] !== null) {
            require_once __DIR__ . '/../../models/Tenant.php';
            $tenantModel = new Tenant($db);
            $tenantData = $tenantModel->findById((int) $userLookup['tenant_id']);
            if ($tenantData && !empty($tenantData['slug'])) {
                $hintSlug = $tenantData['slug'];
            }
        }

        $audit = new Audit($db);
        $audit->log(null, null, 'auth.login_failed_no_tenant', 'user', null, ['email' => $email]);

        if ($hintSlug) {
            Response::error(
                'Log in via de pagina van je locatie.',
                'TENANT_LOGIN_REQUIRED',
                403,
                ['tenant_slug' => $hintSlug]
            );
        } else {
            Response::error('Ongeldig e-mailadres of wachtwoord', 'INVALID_CREDENTIALS', 401);
        }
    }

    // It's a superadmin — proceed without tenant context
    $tenantId = null;
}

// Load dependencies
$db = Database::getInstance()->getConnection();
$authService = new AuthService($db);

// Attempt login (AuthService handles superadmin vs tenant user internally)
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

// Staff (admin, bartender, superadmin) are always active —
// only guests go through the gated onboarding verification flow.
$accountStatus = ($user['role'] !== 'guest')
    ? 'active'
    : ($user['account_status'] ?? 'unverified');

// For guests: check if email is verified — if not, redirect to verification page
// Only enforce if the migration has been run (email_verified_at column exists)
$needsEmailVerification = (
    $user['role'] === 'guest'
    && array_key_exists('email_verified_at', $user)
    && empty($user['email_verified_at'])
);

if ($needsEmailVerification) {
    // Look up tenant slug for verification redirect
    $tenantSlug = null;
    if ($user['tenant_id'] !== null) {
        require_once __DIR__ . '/../../models/Tenant.php';
        $tenantModel = new Tenant($db);
        $tenantData = $tenantModel->findById((int) $user['tenant_id']);
        if ($tenantData && !empty($tenantData['slug'])) {
            $tenantSlug = $tenantData['slug'];
        }
    }
    if ($tenantSlug) {
        $redirect = BASE_URL . '/j/' . $tenantSlug . '/verify';
    }
}

Response::success([
    'user' => [
        'id'             => (int) $user['id'],
        'role'           => $user['role'],
        'first_name'     => $user['first_name'],
        'tenant_id'      => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        'account_status' => $accountStatus,
    ],
    'redirect' => $redirect,
]);
