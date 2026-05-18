<?php
declare(strict_types=1);

/**
 * POST /api/marketing/process
 * Process the email queue — sends pending marketing emails (admin only)
 *
 * Body (optional): { batch_size: int } — max emails to process (default 50, max 100)
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
$batchSize = (int) ($input['batch_size'] ?? 50);
$batchSize = min(max($batchSize, 1), 100);

try {
    $result = (new MarketingService($db))->processQueue($tenantId, $batchSize);

    Response::success([
        'message'   => "Wachtrij verwerkt: {$result['sent']} verstuurd, {$result['failed']} mislukt",
        'processed' => $result['processed'],
        'sent'      => $result['sent'],
        'failed'    => $result['failed'],
    ]);
} catch (\Throwable $e) {
    error_log('[Marketing Process] ' . $e->getMessage());
    Response::internalError('Wachtrij verwerken mislukt');
}
