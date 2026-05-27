<?php
/**
 * FCM Token Subscription Handler
 * Stores FCM tokens for push notifications
 */

require_once __DIR__ . '/../../services/PushService.php';

// Validate request method
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get FCM token from request
$input = getJsonInput();
$fcmToken = $input['fcm_token'] ?? $_POST['fcm_token'] ?? null;

if (!$fcmToken) {
    Response::error('FCM token required', 'INVALID_DATA', 400);
}

$userId = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$db = Database::getInstance()->getConnection();
$pushService = new PushService($db);

try {
    // Store FCM token for user AND set push_enabled = 1
    $success = $pushService->storeFcmToken($userId, $fcmToken);
    if ($success) {
        // Also set push_enabled in users table
        $stmt = $db->prepare("UPDATE users SET push_enabled = 1 WHERE id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        
        (new Audit($db))->log(
            $tenantId,
            $userId,
            'push.fcm_token_stored',
            'user',
            $userId
        );

        Response::success([
            'message' => 'FCM token stored successfully',
            'push_enabled' => 1,
        ]);
    } else {
        Response::error('Failed to store FCM token', 'DB_ERROR', 500);
    }
} catch (\Throwable $e) {
    error_log('[Push Subscribe] Error: ' . $e->getMessage());
    Response::internalError('Failed to store FCM token');
}