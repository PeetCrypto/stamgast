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

// Destroy session via AuthService
$authService = new AuthService($db);
$authService->logout();

Response::success([
    'redirect' => '/login',
]);
