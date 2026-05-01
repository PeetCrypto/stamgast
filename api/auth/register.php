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

// Validate required fields
if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
    Response::error('Alle verplichte velden moeten ingevuld zijn', 'MISSING_FIELDS', 400);
}

// Resolve tenant_id — strategy: slug > explicit id > session
if (!empty($tenantSlug)) {
    $tenantModel = new Tenant(Database::getInstance()->getConnection());
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
$db = Database::getInstance()->getConnection();
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

// Auto-login after registration: start session
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

// Send guest confirmation email (non-blocking — failure does not affect registration)
try {
    $tenantModel = new Tenant($db);
    $tenant      = $tenantModel->findById($tenantId);
    $tenantName  = $tenant ? $tenant['name'] : 'REGULR.vip';

    $verificationCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    sendGuestConfirmationEmail($db, $email, $tenantName, $verificationCode, $tenantId);
} catch (\Throwable $e) {
    error_log('Guest confirmation email failed: ' . $e->getMessage());
}

Response::success([
    'user_id'  => $result['user_id'],
    'redirect' => '/dashboard',
], 201);
