<?php
declare(strict_types=1);

/**
 * POST /api/push/save-preference
 * Save push enabled preference for the authenticated guest
 * 
 * Body: { push_enabled: 1|0 }
 */

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$input = getJsonInput();
$pushEnabled = isset($input['push_enabled']) ? (int)$input['push_enabled'] : 1;

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare("UPDATE users SET push_enabled = :push_enabled WHERE id = :user_id");
    $stmt->execute([
        ':push_enabled' => $pushEnabled,
        ':user_id' => $userId
    ]);

    (new Audit($db))->log(
        $tenantId,
        $userId,
        $pushEnabled ? 'push.preference_enabled' : 'push.preference_disabled',
        'user',
        $userId
    );

    Response::success([
        'message' => 'Voorkeur opgeslagen',
        'push_enabled' => $pushEnabled,
    ]);
} catch (\Throwable $e) {
    Response::internalError('Opslaan mislukt');
}