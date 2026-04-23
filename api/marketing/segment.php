<?php
declare(strict_types=1);

/**
 * POST /api/marketing/segment
 * Segment users based on criteria (admin only)
 *
 * Body: { criteria: { last_activity_days?: int, min_balance?: int, tier_name?: string } }
 */

require_once __DIR__ . '/../../services/MarketingService.php';

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
if (!(bool) ($tenant['feature_marketing'] ?? true)) {
    Response::error('Marketing module is uitgeschakeld', 'FEATURE_DISABLED', 403);
}

$input = getJsonInput();
$criteria = $input['criteria'] ?? [];

if (!is_array($criteria)) {
    Response::error('criteria moet een object zijn', 'VALIDATION_ERROR', 422);
}

// Sanitize criteria
$sanitizedCriteria = [];
if (isset($criteria['last_activity_days'])) {
    $sanitizedCriteria['last_activity_days'] = max(0, (int) $criteria['last_activity_days']);
}
if (isset($criteria['min_balance'])) {
    $sanitizedCriteria['min_balance'] = max(0, (int) $criteria['min_balance']);
}
if (isset($criteria['tier_name'])) {
    $sanitizedCriteria['tier_name'] = trim((string) $criteria['tier_name']);
}

$marketingService = new MarketingService($db);

try {
    $result = $marketingService->segmentUsers($tenantId, $sanitizedCriteria);

    (new Audit($db))->log(
        $tenantId,
        currentUserId(),
        'marketing.segment',
        null,
        null,
        ['criteria' => $sanitizedCriteria, 'count' => $result['count']]
    );

    Response::success($result);
} catch (\Throwable $e) {
    Response::internalError('Segmentatie mislukt');
}
