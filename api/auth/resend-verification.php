<?php
declare(strict_types=1);

/**
 * POST /api/auth/resend-verification
 * Resends the email verification code to the currently logged-in user.
 * Rate limited: max 1 resend per 60 seconds.
 */

require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/Email/email_helpers.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Must be logged in
if (!isLoggedIn()) {
    Response::error('Niet ingelogd', 'NOT_AUTHENTICATED', 401);
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
    Response::error('E-mail is al geverifieerd', 'ALREADY_VERIFIED', 400);
}

// Rate limit: check if code was generated less than 60 seconds ago
// We use the updated_at field as a proxy — if the code was just set, updated_at is recent
$stmt = $db->prepare(
    'SELECT `updated_at` FROM `users` WHERE `id` = :id AND `email_verification_code` IS NOT NULL'
);
$stmt->execute([':id' => $userId]);
$row = $stmt->fetch();
if ($row) {
    $updatedAt = strtotime($row['updated_at']);
    $elapsed = time() - $updatedAt;
    if ($elapsed < 60) {
        $remaining = 60 - $elapsed;
        Response::error("Wacht nog {$remaining} seconden voordat je een nieuwe code aanvraagt.", 'RATE_LIMITED', 421);
    }
}

// Generate new verification code
$verificationCode = strtoupper(bin2hex(random_bytes(4)));

// Store new code
$stmt = $db->prepare(
    'UPDATE `users` SET `email_verification_code` = :code WHERE `id` = :id'
);
$stmt->execute([
    ':code' => $verificationCode,
    ':id'   => $userId,
]);

// Send verification email
try {
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById((int) $user['tenant_id']);
    $tenantName = $tenant ? $tenant['name'] : 'REGULR.vip';
    $guestName = trim($user['first_name'] . ' ' . $user['last_name']]);

    sendGuestConfirmationEmail($db, $user['email'], $tenantName, $verificationCode, (int) $user['tenant_id'], $guestName);
} catch (\Throwable $e) {
    error_log('Resend verification email failed: ' . $e->getMessage());
    Response::error('Kon geen e-mail versturen. Probeer het opnieuw.', 'EMAIL_FAILED', 500);
}

Response::success([
    'message' => 'Nieuwe verificatiecode verstuurd naar ' . $user['email'],
]);

// Send verification email
try {
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById((int) $user['tenant_id']);
    $tenantName = $tenant ? $tenant['name'] : 'REGULR.vip';
    $guestName = trim($user['first_name'] . ' ' . $user['last_name']]);

    sendGuestConfirmationEmail($db, $user['email'], $tenantName, $verificationCode, (int) $user['tenant_id'], $guestName);
} catch (\Throwable $e) {
    error_log('Resend verification email failed: ' . $e->getMessage());
    Response::error('Kon geen e-mail versturen. Probeer het opnieuw.', 'EMAIL_FAILED', 500);
}

Response::success([
    'message' => 'Nieuwe verificatiecode verstuurd naar ' . $user['email'],
]);

// Send verification email
try {
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById((int) $user['tenant_id']);
    $tenantName = $tenant ? $tenant['name'] : 'REGULR.vip';
    $guestName = trim($user['first_name'] . ' ' . $user['last_name']);

    sendGuestConfirmationEmail($db, $user['email'], $tenantName, $verificationCode, (int) $user['tenant_id'], $guestName);
} catch (\Throwable $e) {
    error_log('Resend verification email failed: ' . $e->getMessage());
    Response::error('Kon geen e-mail versturen. Probeer het opnieuw.', 'EMAIL_FAILED', 500);
}

Response::success([
    'message' => 'Nieuwe verificatiecode verstuurd naar ' . $user['email'],
]);

// Send verification email
try {
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById((int) $user['tenant_id']);
    $tenantName = $tenant ? $tenant['name'] : 'REGULR.vip';
    $guestName = trim($user['first_name'] . ' ' . $user['last_name']);

    sendGuestConfirmationEmail($db, $user['email'], $tenantName, $verificationCode, $user['tenant_id'], $guestName);
} catch (\Throwable $e) {
    error_log('Resend verification email failed: ' . $e->getMessage());
    Response::error('Kon geen e-mail versturen. Probeer het opnieuw.', 'EMAIL_FAILED', 500);
}

Response::success([
    'message' => 'Nieuwe verificatiecode verstuurd naar ' . $user['email'],
]);
?>_ALLOWED', 405);
}

// Must be logged in
if (!isLoggedIn()) {
    Response::error('Niet ingelogd', 'NOT_AUTHENTICATED', 401);
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
    Response::error('E-mail is al geverifieerd', 'ALREADY_VERIFIED', 400);
}

// Rate limit: check if code was generated less than 60 seconds ago
// We use the updated_at field as a proxy — if the code was just set, updated_at is recent
$stmt = $db->prepare(
    'SELECT `updated_at` FROM `users` WHERE `id` = :id AND `email_verification_code` IS NOT NULL'
);
$stmt->execute([':id' => $userId]);
$row = $stmt->fetch();
if ($row) {
    $updatedAt = strtotime($row['updated_at']);
    $elapsed = time() - $updatedAt;
    if ($elapsed < 60) {
        $remaining = 60 - $elapsed;
        Response::error("Wacht nog {$remaining} seconden voordat je een nieuwe code aanvraagt.", 'RATE_LIMITED', 429);
    }
}

// Generate new verification code
$verificationCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// Store new code
$stmt = $db->prepare(
    'UPDATE `users` SET `email_verification_code` = :code WHERE `id` = :id'
);
$stmt->execute([
    ':code' => $verificationCode,
    ':id'   => $userId,
]);

// Send verification email
try {
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById((int) $user['tenant_id']);
    $tenantName = $tenant ? $tenant['name'] : 'REGULR.vip';
    $guestName = trim($user['first_name'] . ' ' . $user['last_name']);

    sendGuestConfirmationEmail($db, $user['email'], $tenantName, $verificationCode, (int) $user['tenant_id'], $guestName);
} catch (\Throwable $e) {
    error_log('Resend verification email failed: ' . $e->getMessage());
    Response::error('Kon geen e-mail versturen. Probeer het opnieuw.', 'EMAIL_FAILED', 500);
}

Response::success([
    'message' => 'Nieuwe verificatiecode verstuurd naar ' . $user['email'],
]);
