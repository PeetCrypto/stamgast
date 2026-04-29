<?php
declare(strict_types=1);

/**
 * POST /api/notification/mark_read
 * Mark a single notification or all notifications as read.
 *
 * Auth: guest+ (any authenticated user)
 * Request:  { notification_id: int }           — mark one as read
 *           { mark_all: true }                  — mark all as read
 * Response: { success: true, updated: int }
 */

require_once __DIR__ . '/../../models/Notification.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $notifModel = new Notification($db);

    // Mark all as read
    if (!empty($input['mark_all'])) {
        $count = $notifModel->markAllAsRead($userId, $tenantId);
        Response::success(['updated' => $count]);
    }

    // Mark single as read
    $notificationId = (int) ($input['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        Response::error('Ongeldig notification_id', 'VALIDATION_ERROR', 422);
    }

    $updated = $notifModel->markAsRead($notificationId, $userId, $tenantId);

    if (!$updated) {
        Response::notFound('Melding niet gevonden of al gelezen');
    }

    Response::success(['updated' => 1]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Bijwerken mislukt: ' . $e->getMessage());
    }
    Response::internalError('Bijwerken mislukt');
}
