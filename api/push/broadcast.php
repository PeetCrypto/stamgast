<?php
declare(strict_types=1);

/**
 * POST /api/push/broadcast
 * Send a push notification to all subscribed guests of a tenant (admin only)
 *
 * Body: { title: string, body: string }
 */

require_once __DIR__ . '/../../services/PushService.php';
require_once __DIR__ . '/../../models/Notification.php';

$method = $_SERVER['REQUEST_METHOD'];
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

    // Persist to inbox for ALL guest users (not just push subscribers)
    $guestStmt = $db->prepare(
        "SELECT id FROM users WHERE tenant_id = :tid AND role = 'guest' AND (account_status != 'suspended' OR account_status IS NULL)"
    );
    $guestStmt->execute([':tid' => $tenantId]);
    $guests = $guestStmt->fetchAll(PDO::FETCH_ASSOC);

    $notifModel = new Notification($db);
    $inboxCount = 0;
    foreach ($guests as $guest) {
        $notifModel->create([
            'tenant_id' => $tenantId,
            'user_id'   => (int) $guest['id'],
            'type'      => 'system',
            'icon'      => '📢',
            'title'     => $title,
            'body'      => $body,
            'color'     => 'var(--accent-primary)',
        ]);
        $inboxCount++;
    }

    (new Audit($db))->log(
        $tenantId,
        currentUserId(),
        'push.broadcast_sent',
        'tenant',
        $tenantId,
        ['title' => $title, 'sent' => $result['sent'], 'failed' => $result['failed'], 'total' => $result['total_subscriptions'], 'inbox_count' => $inboxCount]
    );

    Response::success([
        'message'             => 'Broadcast verzonden',
        'sent'                => $result['sent'],
        'failed'              => $result['failed'],
        'total_subscriptions' => $result['total_subscriptions'],
        'inbox_count'         => $inboxCount,
    ]);
} catch (\Throwable $e) {
    error_log('[Push Broadcast] Error: ' . $e->getMessage());
    Response::internalError('Broadcast verzenden mislukt');
}