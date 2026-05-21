<?php
declare(strict_types=1);

/**
 * POST /api/push/unsubscribe
 * Removes FCM token for the current user (guest disables push notifications)
 */

require_once __DIR__ . '/../../services/PushService.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$db = Database::getInstance()->getConnection();
$pushService = new PushService($db);

try {
    $success = $pushService->removeFcmToken($userId);

    if ($success) {
        (new Audit($db))->log(
            $tenantId,
            $userId,
            'push.fcm_token_removed',
            'user',
            $userId
        );

        Response::success([
            'message' => 'Push notificaties uitgeschakeld'
        ]);
    } else {
        Response::error('Failed to remove FCM token', 'DB_ERROR', 500);
    }
} catch (\Throwable $e) {
    error_log('[Push Unsubscribe] Error: ' . $e->getMessage());
    Response::internalError('Failed to remove FCM token');
}
