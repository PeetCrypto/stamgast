<?php
declare(strict_types=1);

/**
 * POST /api/auth/logout
 * Destroys the current session
 */

require_once __DIR__ . '/../../services/AuthService.php';
require_once __DIR__ . '/../../utils/audit.php';

// Only allow POST
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Check if user is logged in
if (!isLoggedIn()) {
    Response::error('Niet ingelogd', 'NOT_AUTHENTICATED', 401);
}

// Log logout before destroying session
$db = Database::getInstance()->getConnection();
$audit = new Audit($db);
$audit->log(
    (int) currentTenantId(),
    (int) currentUserId(),
    'auth.logout',
    'user',
    (int) currentUserId()
);

// Capture guest slug before destroying session (for branded redirect)
$role = $_SESSION['role'] ?? '';
$guestSlug = ($role === 'guest' && isset($_SESSION['tenant']['slug']))
    ? $_SESSION['tenant']['slug']
    : null;

// Destroy session via AuthService
$authService = new AuthService($db);
$authService->logout();

// Build redirect URL: guests go to /j/{slug}, others to /login
$redirectUrl = '/login';
if ($guestSlug) {
    $redirectUrl = '/j/' . $guestSlug;
}

Response::success([
    'redirect' => $redirectUrl,
]);
