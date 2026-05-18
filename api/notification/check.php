<?php
declare(strict_types=1);

/**
 * GET /api/notification/check
 * Polling endpoint for in-app notifications.
 * Returns unread count and latest unread notifications for the logged-in user.
 *
 * Auth: guest+ (any authenticated user)
 * Response: { success: true, data: { unread_count: int, notifications: [...] } }
 */

require_once __DIR__ . '/../../models/Notification.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $notifModel = new Notification($db);

    $unreadCount = $notifModel->getUnreadCount($userId, $tenantId);

    // Fetch latest 5 unread notifications for toast display
    $stmt = $db->prepare(
        'SELECT `id`, `type`, `icon`, `title`, `body`, `created_at`
         FROM `notifications`
         WHERE `user_id` = :uid
           AND `tenant_id` = :tid
           AND `is_read` = 0
           AND `deleted_at` IS NULL
         ORDER BY `created_at` DESC
         LIMIT 5'
    );
    $stmt->execute([':uid' => $userId, ':tid' => $tenantId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'unread_count'  => $unreadCount,
        'notifications' => $notifications,
    ]);
} catch (\Throwable $e) {
    error_log('[Notification Check] Error: ' . $e->getMessage());
    Response::internalError('Check mislukt');
}
