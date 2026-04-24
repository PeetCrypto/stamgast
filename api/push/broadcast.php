<?php
declare(strict_types=1);

/**
 * POST /api/push/broadcast
 * Send a push notification to ALL subscribed users in the tenant (admin only)
 *
 * Body: { title: string, body: string }
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

$title = trim($input['title'] ?? '');
$body  = trim($input['body'] ?? '');

// Validate
if ($title === '') {
    Response::error('title is verplicht', 'VALIDATION_ERROR', 422);
}
if ($body === '') {
    Response::error('body is verplicht', 'VALIDATION_ERROR', 422);
}
if (mb_strlen($title) > 100) {
    Response::error('title mag maximaal 100 tekens bevatten', 'VALIDATION_ERROR', 422);
}
if (mb_strlen($body) > 500) {
    Response::error('body mag maximaal 500 tekens bevatten', 'VALIDATION_ERROR', 422);
}

$pushService = new PushService($db);

try {
    $result = $pushService->broadcastNotification($tenantId, $title, $body);

    (new Audit($db))->log(
        $tenantId,
        currentUserId(),
        'push.broadcast_sent',
        'tenant',
        $tenantId,
        ['title' => $title, 'sent' => $result['sent'], 'failed' => $result['failed'], 'total' => $result['total_subscriptions']]
    );

    Response::success([
        'message'            => 'Broadcast verzonden',
        'sent'               => $result['sent'],
        'failed'             => $result['failed'],
        'total_subscriptions' => $result['total_subscriptions'],
    ]);
} catch (\Throwable $e) {
    Response::internalError('Broadcast verzenden mislukt');
}
