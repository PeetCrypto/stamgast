<?php
declare(strict_types=1);

/**
 * GET /api/marketing/queue
 * Get email queue status counts (admin only)
 */

require_once __DIR__ . '/../../services/MarketingService.php';

if ($method !== 'GET') {
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
if (!(bool) ($tenant['feature_marketing'] ?? true)) {
    Response::error('Marketing module is uitgeschakeld', 'FEATURE_DISABLED', 403);
}

$marketingService = new MarketingService($db);

try {
    $status = $marketingService->getQueueStatus($tenantId);
    Response::success($status);
} catch (\Throwable $e) {
    Response::internalError('Queue status ophalen mislukt');
}
