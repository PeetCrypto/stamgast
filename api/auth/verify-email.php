<?php
declare(strict_types=1);

/**
 * POST /api/auth/verify-email
 * Verifies a guest's email address using the verification code
 * sent after registration.
 */

require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../utils/audit.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Must be logged in
if (!isLoggedIn()) {
    Response::error('Niet ingelogd', 'NOT_AUTHENTICATED', 401);
}

// Get JSON input
$input = getJsonInput();

$code = strtoupper(trim($input['code'] ?? ''));

if (empty($code) || strlen($code) !== 8) {
    Response::error('Voer een geldige 8-tekens code in', 'INVALID_CODE', 400);
}

$db = Database::getInstance()->getConnection();
$userId = currentUserId();

// Fetch user
$userModel = new User($db);
$user = $userModel->findById($userId);

if ($user === null) {
    Response::error('Gebruiker niet gevonden', 'USER_NOT_FOUND', 404);
}

// Already verified?
if (!empty($user['email_verified_at'])) {
    Response::success([
        'message'  => 'E-mail is al geverifieerd',
        'redirect' => '/dashboard',
    ]);
}

// Check code match
if (empty($user['email_verification_code']) || $user['email_verification_code'] !== $code) {
    // Log failed attempt
    $audit = new Audit($db);
    $audit->log(
        $user['tenant_id'],
        $userId,
        'auth.email_verify_failed',
        'user',
        $userId,
        ['code_attempt' => substr($code, 0, 2) . '******']
    );

    Response::error('Ongeldige verificatiecode. Controleer je e-mail en probeer opnieuw.', 'CODE_MISMATCH', 400);
}

// Code matches — mark email as verified
$stmt = $db->prepare(
    'UPDATE `users` SET `email_verified_at` = NOW(), `email_verification_code` = NULL WHERE `id` = :id'
);
$stmt->execute([':id' => $userId]);

// Log successful verification
$audit = new Audit($db);
$audit->log(
    $user['tenant_id'],
    $userId,
    'auth.email_verified',
    'user',
    $userId
);

Response::success([
    'message'  => 'E-mail succesvol geverifieerd!',
    'redirect' => '/dashboard',
]);
