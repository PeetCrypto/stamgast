<?php
declare(strict_types=1);

/**
 * POST /api/push/send_notification
 * Send a push notification to a specific user (admin only)
 *
 * Body: { user_id: int, title: string, body: string }
 */

require_once __DIR__ . '/../../services/PushService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$tenantId = currentTenantId();
if ($tenantId === null) {
    Response::unauthorized();
}

$db = Database::getInstance()->getConnection();

// Check feature toggle
$tenant = (new Tenant($db))->findById($tenantId);
if ($tenant === null) {
    Response::error('Tenant niet gevonden', 'NOT_FOUND', 404);
}
if (!(bool) ($tenant['feature_push'] ?? true)) {
    Response::error('Push notificaties zijn uitgeschakeld', 'FEATURE_DISABLED', 403);
}

$input = getJsonInput();

$userId = (int) ($input['user_id'] ?? 0);
$title  = trim($input['title'] ?? '');
$body   = trim($input['body'] ?? '');

// Validate
if ($userId <= 0) {
    Response::error('user_id is verplicht', 'VALIDATION_ERROR', 422);
}
if ($title === '') {
    Response::error('title is verplicht', 'VALIDATION_ERROR', 422);
}
if ($body === '') {
    Response::error('body is verplicht', 'VALIDATION_ERROR', 422);
}

// Verify target user belongs to same tenant
$targetUser = (new User($db))->findById($userId);
if ($targetUser === null || (int) $targetUser['tenant_id'] !== $tenantId) {
    Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
}

$pushService = new PushService($db);

try {
    $result = $pushService->sendNotification($userId, $tenantId, $title, $body);

    (new Audit($db))->log(
        $tenantId,
        currentUserId(),
        'push.notification_sent',
        'user',
        $userId,
        ['title' => $title, 'sent' => $result['sent'], 'failed' => $result['failed']]
    );

    Response::success([
        'message' => 'Notificatie verzonden',
        'sent'    => $result['sent'],
        'failed'  => $result['failed'],
    ]);
} catch (\Throwable $e) {
    Response::internalError('Notificatie verzenden mislukt');
}
