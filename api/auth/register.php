<?php
declare(strict_types=1);

/**
 * POST /api/auth/register
 * Registers a new guest user and creates their wallet
 */

require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../utils/validator.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../services/Email/email_helpers.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get JSON input
$input = getJsonInput();

$email      = trim($input['email'] ?? '');
$password   = $input['password'] ?? '';
$firstName  = trim($input['first_name'] ?? '');
$lastName   = trim($input['last_name'] ?? '');
$birthdate  = trim($input['birthdate'] ?? '');
$tenantId   = isset($input['tenant_id']) ? (int) $input['tenant_id'] : null;
$tenantSlug = trim($input['tenant_slug'] ?? '');

// ─────────────────────────────────────────────────────────────────
// REGISTRATION PROTECTION: Honeypot
// A hidden field that bots auto-fill but humans never see.
// If filled → silently reject (fake success to avoid tipping off bots).
// ─────────────────────────────────────────────────────────────────
$honeypot = trim($input['website'] ?? '');
if (!empty($honeypot)) {
    error_log('Registration honeypot triggered: email=' . $email . ' ip=' . getClientIP());
    // Fake success — don't reveal the honeypot exists
    Response::success([
        'user_id'  => 0,
        'redirect' => '/dashboard',
    ], 201);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// REGISTRATION PROTECTION: Per-email rate limit
// Max 5 registration attempts per email per hour.
// NOTE: Per-IP limiting is NOT used because hospitality venues share
// a single public IP (WiFi) — per-IP would block legitimate guests.
// ─────────────────────────────────────────────────────────────────
$db = Database::getInstance()->getConnection();
$regRateCheck = $db->prepare(
    "SELECT COUNT(*) FROM `audit_log`
     WHERE `action` = 'auth.register_attempt'
     AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.email'))) = :email
     AND `created_at` >= (NOW() - INTERVAL 1 HOUR)"
);
$regRateCheck->execute([':email' => strtolower($email)]);
if ((int) $regRateCheck->fetchColumn() >= 5) {
    Response::error(
        'Te veel registratiepogingen voor dit e-mailadres. Probeer het over een uur opnieuw.',
        'REGISTRATION_RATE_LIMITED',
        429
    );
    exit;
}

// Log this attempt (before validation — counts both successes and failures)
$auditReg = new Audit($db);
$auditReg->log(
    $tenantId ?? null,
    null,
    'auth.register_attempt',
    'user',
    null,
    ['email' => $email, 'ip' => getClientIP()]
);

// Validate required fields
if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
    Response::error('Alle verplichte velden moeten ingevuld zijn', 'MISSING_FIELDS', 400);
}

// Resolve tenant_id — strategy: slug > explicit id > session
if (!empty($tenantSlug)) {
    $tenantModel = new Tenant($db);
    $tenantBySlug = $tenantModel->findBySlug($tenantSlug);
    if ($tenantBySlug === null) {
        Response::error('Ongeldige locatie', 'INVALID_TENANT', 400);
    }
    if (!(bool) $tenantBySlug['is_active']) {
        Response::error('Deze locatie is niet actief', 'TENANT_INACTIVE', 403);
    }
    $tenantId = (int) $tenantBySlug['id'];
}

if ($tenantId === null) {
    $tenantId = currentTenantId();
}

if ($tenantId === null) {
    Response::error('Tenant ID is verplicht', 'MISSING_TENANT', 400);
}

// Validate input using fluent validator
$validator = new Validator();
$validator->email('email', $email)
          ->string('first_name', $firstName, 2, 100)
          ->string('last_name', $lastName, 2, 100);

if (!empty($birthdate)) {
    $validator->date('birthdate', $birthdate);
}

$validator->validate();

// Load dependencies
$authService = new AuthService($db);

// Attempt registration
$result = $authService->register([
    'email'      => $email,
    'password'   => $password,
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'birthdate'  => $birthdate ?: null,
], $tenantId);

if (!$result['success']) {
    Response::error($result['error'], 'REGISTRATION_FAILED', 400);
}

// ─────────────────────────────────────────────────────────────────
// SECURITY (C-1 FIX): Already-registered accounts must NOT be
// auto-logged-in. Previously startSession() ran for ALL results
// (including already_registered), allowing full account takeover
// with just an email address + public tenant slug.
// Now: existing users are redirected to the login page WITHOUT
// a session. Only newly created accounts get auto-logged-in below.
// ─────────────────────────────────────────────────────────────────
if (!empty($result['already_registered'])) {
    // Send "you already have an account" email (non-blocking)
    try {
        $tenantModel = new Tenant($db);
        $tenant      = $tenantModel->findById($tenantId);
        $tenantName  = $tenant ? $tenant['name'] : 'REGULR.vip';
        $tenantSlug  = $tenant ? $tenant['slug'] : '';
        $guestName   = trim($firstName . ' ' . $lastName);
        $loginUrl    = $tenantSlug
            ? FULL_BASE_URL . '/j/' . $tenantSlug . '/login'
            : FULL_BASE_URL . '/login';
        $forgotPasswordUrl = $tenantSlug
            ? FULL_BASE_URL . '/j/' . $tenantSlug . '/forgot-password'
            : FULL_BASE_URL . '/forgot-password';

        sendGuestAlreadyRegisteredEmail($db, $email, $tenantName, $tenantSlug, $guestName, $loginUrl, $forgotPasswordUrl);
    } catch (\Throwable $e) {
        error_log('Guest already-registered email failed: ' . $e->getMessage());
    }

    // Redirect to login page — do NOT start a session
    $redirect = !empty($tenantSlug)
        ? '/j/' . $tenantSlug . '/login'
        : '/login';

    Response::success([
        'already_registered' => true,
        'redirect'           => $redirect,
    ], 200);
}

// New user only: auto-login after registration (safe — account was just created)
$userModel = new User($db);
$user = $userModel->findById($result['user_id']);

if ($user !== null) {
    $authService->startSession($user);
}

// Log registration
$audit = new Audit($db);
$audit->log(
    $tenantId,
    $result['user_id'],
    'auth.register',
    'user',
    $result['user_id']
);

// Generate and store email verification code
$verificationCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

try {
    // Store code in user record
    $stmt = $db->prepare('UPDATE `users` SET `email_verification_code` = :code WHERE `id` = :id');
    $stmt->execute([':code' => $verificationCode, ':id' => $result['user_id']]);
} catch (\Throwable $e) {
    error_log('Failed to store verification code: ' . $e->getMessage());
}

// Send guest confirmation email (non-blocking — failure does not affect registration)
try {
    $tenantModel = new Tenant($db);
    $tenant      = $tenantModel->findById($tenantId);
    $tenantName  = $tenant ? $tenant['name'] : 'REGULR.vip';
    $tenantSlug  = $tenant ? $tenant['slug'] : '';
    $guestName = trim($firstName . ' ' . $lastName);

    sendGuestConfirmationEmail($db, $email, $tenantName, $verificationCode, $tenantId, $guestName);
} catch (\Throwable $e) {
    error_log('Guest confirmation email failed: ' . $e->getMessage());
}

// Redirect to email verification page instead of dashboard
$verifyRedirect = '/dashboard';
if (!empty($tenantSlug)) {
    $verifyRedirect = '/j/' . $tenantSlug . '/verify';
}

Response::success([
    'user_id'  => $result['user_id'],
    'redirect' => $verifyRedirect,
], 201);
