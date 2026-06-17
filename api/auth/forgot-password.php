<?php
declare(strict_types=1);

/**
 * POST /api/auth/forgot-password
 * Generates a password reset token and sends it via email
 * Always returns success to prevent email enumeration
 */

require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../services/Email/email_helpers.php';
require_once __DIR__ . '/../../utils/RateLimiter.php';

// Only allow POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get JSON input
$input = getJsonInput();

$email      = trim($input['email'] ?? '');
$tenantSlug = trim($input['tenant_slug'] ?? '');

if (empty($email) || !isValidEmail($email)) {
    Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
}

$db = Database::getInstance()->getConnection();

// ─────────────────────────────────────────────────────────────
// RATE LIMITING — prevent email enumeration / spam abuse
// ─────────────────────────────────────────────────────────────
$rateLimiter = new RateLimiter($db);
$clientIp = getClientIP();

if ($rateLimiter->isIpRateLimited($clientIp)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: ' . RateLimiter::WINDOW_SECONDS);
    echo json_encode([
        'success' => false,
        'error'   => 'Te veel aanvragen. Probeer het later opnieuw.',
        'code'    => 'RATE_LIMITED',
    ]);
    exit;
}
$userModel = new User($db);
$tenantModel = new Tenant($db);

// Resolve tenant from slug or session
$tenantId = null;
$tenant = null;

if (!empty($tenantSlug)) {
    $tenant = $tenantModel->findBySlug($tenantSlug);
    if ($tenant && (bool) $tenant['is_active']) {
        $tenantId = (int) $tenant['id'];
    }
}

if ($tenantId === null) {
    $tenantId = currentTenantId();
    if ($tenantId !== null) {
        $tenant = $tenantModel->findById($tenantId);
    }
}

if ($tenantId === null || $tenant === null) {
    // No tenant context — still return success (no info leakage)
    Response::success(['message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een reset-link.']);
}

// Find user by email within this tenant
$user = $userModel->findByEmail($email, $tenantId);

if ($user === null || ($user['role'] ?? '') === 'superadmin') {
    // User not found or is superadmin — still return success (no info leakage)
    Response::success(['message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een reset-link.']);
}

// Check account not suspended
if (($user['account_status'] ?? '') === 'suspended') {
    Response::success(['message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een reset-link.']);
}

// Generate reset token
$token = $userModel->createResetToken((int) $user['id'], $email, $tenantId);

// Build reset link with tenant slug context
$resetSlug = $tenant['slug'] ?? '';
if (!empty($resetSlug)) {
    $resetLink = FULL_BASE_URL . '/j/' . $resetSlug . '/reset-password?token=' . $token;
} else {
    $resetLink = FULL_BASE_URL . '/reset-password?token=' . $token;
}

// Send reset email (non-blocking — failure does not affect response to prevent info leakage)
try {
    $guestName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    sendGuestPasswordResetEmail($db, $email, $tenant['name'], $resetLink, $tenantId, $guestName);
} catch (\Throwable $e) {
    error_log('Password reset email failed: ' . $e->getMessage());
}

Response::success(['message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een reset-link.']);
