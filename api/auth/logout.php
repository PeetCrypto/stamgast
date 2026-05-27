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

// Capture tenant slug before destroying session (for branded redirect)
// All tenant users (guest, admin, bartender) redirect to /j/{slug}
// Only superadmins go to /login (no tenant context)
$role = $_SESSION['role'] ?? '';
$tenantSlug = null;
if ($role !== 'superadmin' && isset($_SESSION['tenant']['slug'])) {
    $tenantSlug = $_SESSION['tenant']['slug'];
}

// Destroy session via AuthService
$authService = new AuthService($db);
$authService->logout();

// Build redirect URL: tenant users go to /j/{slug}, superadmins to /login
$redirectUrl = '/login';
if ($tenantSlug) {
    $redirectUrl = '/j/' . $tenantSlug;
}

Response::success([
    'redirect' => $redirectUrl,
]);
