<?php
declare(strict_types=1);

/**
 * POST /api/push/unsubscribe
 * Remove push subscription for the authenticated guest
 */

require_once __DIR__ . '/../../services/PushService.php';

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
    // Remove FCM token from user
    $pushService->removeFcmToken($userId);
    
    // Set push_enabled to 0
    $stmt = $db->prepare("UPDATE users SET push_enabled = 0 WHERE id = :user_id");
    $stmt->execute([':user_id' => $userId]);

    (new Audit($db))->log(
        $tenantId,
        $userId,
        'push.unsubscribed',
        'user',
        $userId
    );

    Response::success([
        'message' => 'Push notificaties uitgeschakeld',
        'push_enabled' => 0,
    ]);
} catch (\Throwable $e) {
    Response::internalError('Push uitschakelen mislukt');
}