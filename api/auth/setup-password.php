<?php
declare(strict_types=1);

/**
 * POST /api/auth/setup-password
 * Sets a password for a newly invited user via a one-time setup token (magic link).
 *
 * This replaces the old flow where plaintext passwords were sent via email.
 * The admin/superadmin generates a setup token when creating a user, and the
 * user receives an email with a link to /setup-password?token=XXX.
 *
 * Request body: { token: "xxx", password: "newpassword" }
 */

require_once __DIR__ . '/../../utils/RateLimiter.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$token   = trim($input['token'] ?? '');
$password = $input['password'] ?? '';

if (empty($token)) {
    Response::error('Setup token is verplicht', 'MISSING_TOKEN', 400);
}

if (empty($password)) {
    Response::error('Wachtwoord is verplicht', 'MISSING_FIELDS', 400);
}

// Validate password strength
$v = new Validator();
$v->password('password', $password);
if (!$v->isValid()) {
    $errors = $v->getErrors();
    Response::error(implode(', ', $errors), 'INVALID_PASSWORD', 400);
}

$db = Database::getInstance()->getConnection();

// Rate limiting on setup-password attempts (prevent token brute-force)
$rateLimiter = new RateLimiter($db);
$clientIp = getClientIP();
if ($rateLimiter->isIpRateLimited($clientIp)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: ' . RateLimiter::WINDOW_SECONDS);
    echo json_encode([
        'success' => false,
        'error'   => 'Te veel pogingen. Probeer het later opnieuw.',
        'code'    => 'RATE_LIMITED',
    ]);
    exit;
}

// Find user by setup token hash
// We store the hash, so we need to check all users with a non-null token.
// For efficiency, we use a query that checks token validity.
$userModel = new User($db);

// Look up users with active setup tokens
$stmt = $db->prepare(
    "SELECT `id`, `email`, `tenant_id`, `role`, `first_name`, `password_setup_token_hash`, `password_setup_expires_at`
     FROM `users`
     WHERE `password_setup_token_hash` IS NOT NULL
       AND `password_setup_expires_at` IS NOT NULL
     LIMIT 100"
);
$stmt->execute();
$candidates = $stmt->fetchAll();

$matchedUser = null;
foreach ($candidates as $candidate) {
    if (password_verify($token, $candidate['password_setup_token_hash'])) {
        // Check expiry
        $expiresAt = strtotime($candidate['password_setup_expires_at']);
        if ($expiresAt === false || $expiresAt < time()) {
            // Token expired — skip
            continue;
        }
        $matchedUser = $candidate;
        break;
    }
}

if ($matchedUser === null) {
    // Log failed attempt
    $audit = new Audit($db);
    $audit->log(null, null, 'auth.setup_password_failed', 'user', null, ['ip' => $clientIp]);

    Response::error('Ongeldige of verlopen setup link. Vraag een nieuwe uitnodiging aan.', 'INVALID_TOKEN', 400);
}

// Set the new password
$pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
$passwordHash = password_hash($pepperedPassword, PASSWORD_DEFAULT);

if ($passwordHash === false) {
    Response::error('Wachtwoord hashing mislukt', 'HASH_ERROR', 500);
}

// Update password and clear the setup token (one-time use)
$stmt = $db->prepare(
    "UPDATE `users`
     SET `password_hash` = :hash,
         `password_setup_token_hash` = NULL,
         `password_setup_expires_at` = NULL
     WHERE `id` = :id"
);
$stmt->execute([
    ':hash' => $passwordHash,
    ':id'   => $matchedUser['id'],
]);

// Audit log
$audit = new Audit($db);
$audit->log(
    $matchedUser['tenant_id'] !== null ? (int) $matchedUser['tenant_id'] : null,
    (int) $matchedUser['id'],
    'auth.password_setup_completed',
    'user',
    (int) $matchedUser['id'],
    ['email' => $matchedUser['email']]
);

// Build login URL for redirect
$loginUrl = '/login';
if ($matchedUser['tenant_id'] !== null) {
    $tenantModel = new Tenant($db);
    $tenantData = $tenantModel->findById((int) $matchedUser['tenant_id']);
    if ($tenantData && !empty($tenantData['slug'])) {
        $loginUrl = '/j/' . $tenantData['slug'];
    }
}

Response::success([
    'message'   => 'Wachtwoord ingesteld. Je kunt nu inloggen.',
    'login_url' => $loginUrl,
]);
