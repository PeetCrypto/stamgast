<?php
declare(strict_types=1);

/**
 * POST /api/marketing/compose
 * Compose and queue emails for specific users (admin only)
 *
 * Body: { subject: string, body_html: string, user_ids: int[] }
 */

require_once __DIR__ . '/../../services/MarketingService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$tenantId = currentTenantId();
$adminId  = currentUserId();

if ($tenantId === null || $adminId === null) {
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

$subject  = trim($input['subject'] ?? '');
$bodyHtml = trim($input['body_html'] ?? '');
$userIds  = $input['user_ids'] ?? [];

// Validate
if ($subject === '') {
    Response::error('subject is verplicht', 'VALIDATION_ERROR', 422);
}
if ($bodyHtml === '') {
    Response::error('body_html is verplicht', 'VALIDATION_ERROR', 422);
}
if (!is_array($userIds) || empty($userIds)) {
    Response::error('user_ids moet een niet-lege array zijn', 'VALIDATION_ERROR', 422);
}

// Sanitize user IDs
$userIds = array_map('intval', array_filter($userIds, 'is_numeric'));
if (count($userIds) > 500) {
    Response::error('Maximaal 500 gebruikers per verzending', 'VALIDATION_ERROR', 422);
}

$marketingService = new MarketingService($db);

try {
    $result = $marketingService->composeEmail($tenantId, $adminId, $subject, $bodyHtml, $userIds);

    Response::success([
        'message' => 'Emails in de wachtrij geplaatst',
        'queued'  => $result['queued'],
    ]);
} catch (\Throwable $e) {
    Response::internalError('Emails samenstellen mislukt');
}
