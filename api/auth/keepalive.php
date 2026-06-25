<?php
declare(strict_types=1);

/**
 * GET /api/auth/keepalive
 * Lightweight endpoint dat alleen last_activity vernieuwt.
 * Wordt door de service worker elke 15 min aangeroepen.
 * Retourneert ook of de user nog steeds geldig is (niet suspended).
 */

// Only allow GET
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Moet ingelogd zijn
if (!isLoggedIn()) {
    Response::error('Not authenticated', 'UNAUTHORIZED', 401);
}

// Update last activity
updateLastActivity();

// Controleer account_status voor gasten
$role = $_SESSION['role'] ?? '';
if ($role === 'guest') {
    $db = Database::getInstance()->getConnection();
    $userModel = new User($db);

    // FAIL-OPEN: if the query throws (e.g. a migration locks the users table),
    // do NOT destroy the session. Treat the account as still active and retry
    // on the next keepalive ping. Logging people out because of a transient
    // lock wait is the exact bug we are fixing.
    $accountStatus = 'active';
    try {
        $accountStatus = $userModel->getAccountStatus((int) $_SESSION['user_id']);
    } catch (\Throwable $e) {
        error_log('keepalive: account status check failed (transient?), failing open: ' . $e->getMessage());
    }

    if ($accountStatus === 'suspended') {
        // Account is gesuspendeerd — destroy session
        session_unset();
        session_destroy();
        Response::error('Account suspended', 'ACCOUNT_SUSPENDED', 403);
    }

    // Vernieuw cookie lifetime voor gast (60 dagen vanaf nu)
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), time() + SESSION_COOKIE_LIFETIME_GUEST,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}

Response::success([
    'ok'       => true,
    'role'     => $role,
    'timeout'  => ($role === 'guest') ? SESSION_TIMEOUT_GUEST : SESSION_TIMEOUT,
]);
