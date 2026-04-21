<?php
declare(strict_types=1);

/**
 * GET /api/qr/generate
 * Generate a new HMAC-SHA256 signed QR payload for the authenticated guest.
 * The QR code is valid for 60 seconds and auto-refreshes on the client.
 *
 * Auth: guest+ (any authenticated user)
 * Response: { qr_data: string, expires_at: int }
 */

require_once __DIR__ . '/../../services/QrService.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $qrService = new QrService($db);

    $result = $qrService->generate($userId, $tenantId);

    Response::success([
        'qr_data'    => $result['qr_data'],
        'expires_at' => $result['expires_at'],
    ]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('QR generatie mislukt: ' . $e->getMessage());
    }
    Response::internalError('QR generatie mislukt');
}
