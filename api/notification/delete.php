<?php
declare(strict_types=1);

/**
 * POST /api/notification/delete
 * Soft-delete a notification for the authenticated guest.
 *
 * Auth: guest+ (any authenticated user)
 * Request:  { notification_id: int }
 * Response: { success: true }
 */

require_once __DIR__ . '/../../models/Notification.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$notificationId = (int) ($input['notification_id'] ?? 0);

if ($notificationId <= 0) {
    Response::error('Ongeldig notification_id', 'VALIDATION_ERROR', 422);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $notifModel = new Notification($db);

    $deleted = $notifModel->softDelete($notificationId, $userId, $tenantId);

    if (!$deleted) {
        Response::notFound('Melding niet gevonden of al verwijderd');
    }

    Response::success(['deleted' => true]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Verwijderen mislukt: ' . $e->getMessage());
    }
    Response::internalError('Verwijderen mislukt');
}
