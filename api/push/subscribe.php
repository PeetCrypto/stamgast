<?php
declare(strict_types=1);

/**
 * POST /api/push/subscribe
 * Register a Web Push subscription for the authenticated guest
 *
 * Body: { endpoint: string, p256dh: string, auth: string }
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

$input = getJsonInput();

$endpoint = trim($input['endpoint'] ?? '');
$p256dh   = trim($input['p256dh'] ?? '');
$auth     = trim($input['auth'] ?? '');

// Validate required fields
if ($endpoint === '' || $p256dh === '' || $auth === '') {
    Response::error('endpoint, p256dh en auth zijn verplicht', 'VALIDATION_ERROR', 422);
}

// Validate endpoint URL
if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
    Response::error('Ongeldig endpoint URL', 'VALIDATION_ERROR', 422);
}

// Validate base64 fields (p256dh and auth should be base64-encoded)
if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $p256dh) || !preg_match('/^[A-Za-z0-9+\/]+=*$/', $auth)) {
    Response::error('p256dh en auth moeten base64-geëncodeerd zijn', 'VALIDATION_ERROR', 422);
}

$db = Database::getInstance()->getConnection();
$pushService = new PushService($db);

try {
    $subscriptionId = $pushService->subscribe($userId, $tenantId, $endpoint, $p256dh, $auth);

    (new Audit($db))->log(
        $tenantId,
        $userId,
        'push.subscribed',
        'push_subscription',
        $subscriptionId
    );

    Response::success([
        'subscription_id' => $subscriptionId,
        'message'         => 'Push abonnement geregistreerd',
    ]);
} catch (\Throwable $e) {
    Response::internalError('Push abonnement registreren mislukt');
}
