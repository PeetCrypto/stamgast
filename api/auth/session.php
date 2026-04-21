<?php
declare(strict_types=1);

/**
 * GET /api/auth/session
 * Returns current session info for the frontend
 */

require_once __DIR__ . '/../../services/AuthService.php';

// Only allow GET
if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$db = Database::getInstance()->getConnection();
$authService = new AuthService($db);

$info = $authService->getSessionInfo();

Response::success($info);
