<?php
declare(strict_types=1);

/**
 * POST /api/auth/reset-password
 * Resets a user's password using a valid reset token
 */

// Only allow POST
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get JSON input
$input = getJsonInput();

$token        = trim($input['token'] ?? '');
$password     = $input['password'] ?? '';
$confirmation = $input['password_confirm'] ?? '';

// Validate required fields
if (empty($token) || empty($password) || empty($confirmation)) {
    Response::error('Vul alle velden in', 'MISSING_FIELDS', 400);
}

// Validate password match
if ($password !== $confirmation) {
    Response::error('Wachtwoorden komen niet overeen', 'PASSWORD_MISMATCH', 400);
}

// Validate password strength
$v = new Validator();
$v->password('password', $password);
if (!$v->isValid()) {
    Response::error(implode(', ', $v->getErrors()), 'INVALID_PASSWORD', 400);
}

$db = Database::getInstance()->getConnection();
$userModel = new User($db);

// Find valid token
$resetRecord = $userModel->findValidResetToken($token);

if ($resetRecord === null) {
    Response::error('Deze link is ongeldig of verlopen. Vraag een nieuwe aan.', 'INVALID_TOKEN', 400);
}

// Only allow guest password resets via this flow
if (($resetRecord['role'] ?? '') === 'superadmin') {
    Response::error('Ongeldige aanvraag', 'INVALID_REQUEST', 400);
}

// Hash new password with Argon2id + pepper
$pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
$newHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

if ($newHash === false) {
    Response::error('Wachtwoord kon niet worden opgeslagen', 'HASH_ERROR', 500);
}

// Update password
$userId = (int) $resetRecord['user_id'];
$userModel->updatePassword($userId, $newHash);

// Consume the token
$userModel->consumeResetToken($token);

// Build redirect URL based on tenant slug
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById((int) $resetRecord['tenant_id']);
$slug = $tenant['slug'] ?? '';

if (!empty($slug)) {
    $redirect = BASE_URL . '/j/' . $slug . '?success=' . urlencode('Wachtwoord gewijzigd! Je kunt nu inloggen.');
} else {
    $redirect = BASE_URL . '/login?success=' . urlencode('Wachtwoord gewijzigd! Je kunt nu inloggen.');
}

Response::success([
    'message'  => 'Wachtwoord succesvol gewijzigd.',
    'redirect' => $redirect,
]);
