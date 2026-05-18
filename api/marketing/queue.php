<?php
declare(strict_types=1);

/**
 * GET /api/marketing/queue
 * Get email queue status counts + paginated items (admin only)
 *
 * Query params:
 *   page   (int)    — Page number, default 1
 *   per_page (int)  — Items per page, default 20, max 100
 *   status (string) — Filter: '', 'pending', 'sent', 'failed'
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

$page = (int) ($_GET['page'] ?? 1);
$perPage = (int) ($_GET['per_page'] ?? 20);
$status = trim($_GET['status'] ?? '');

$marketingService = new MarketingService($db);

try {
    $result = $marketingService->getQueueStatus($tenantId, $page, $perPage, $status);
    Response::success($result);
} catch (\Throwable $e) {
    Response::internalError('Queue status ophalen mislukt');
}
