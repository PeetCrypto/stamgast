<?php
declare(strict_types=1);

/**
 * POST /api/admin/suspend_user
 * Admin can suspend (block) or unsuspend (unblock) a guest account.
 *
 * Auth: admin+ (enforced by router)
 *
 * Request:  { user_id: int, action: 'suspend'|'unsuspend', reason?: string }
 * Response: { success: true, data: { user_id, account_status } }
 */

require_once __DIR__ . '/../../models/User.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$userId = (int) ($input['user_id'] ?? 0);
$action = trim($input['action'] ?? '');
$reason = trim($input['reason'] ?? '');

// ── VALIDATIE ────────────────────────────────────────────────
if ($userId <= 0) {
    Response::error('user_id is vereist', 'VALIDATION_ERROR', 422);
}
if (!in_array($action, ['suspend', 'unsuspend'], true)) {
    Response::error('Action moet "suspend" of "unsuspend" zijn', 'VALIDATION_ERROR', 422);
}
if ($action === 'suspend' && $reason === '') {
    Response::error('Een reden is verplicht bij blokkeren', 'VALIDATION_ERROR', 422);
}

$tenantId = currentTenantId();
$adminId = currentUserId();

if ($tenantId === null || $adminId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $userModel = new User($db);

    // Fetch user
    $user = $userModel->findById($userId);
    if ($user === null) {
        Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
    }

    // Tenant check: admin can only manage users in their own tenant
    if ((int) $user['tenant_id'] !== $tenantId) {
        Response::error('Gebruiker behoort niet tot jouw locatie', 'FORBIDDEN', 403);
    }

    // Can only manage guests
    if ($user['role'] !== 'guest') {
        Response::error('Alleen gasten kunnen geblokkeerd worden', 'FORBIDDEN', 403);
    }

    $currentStatus = $user['account_status'] ?? 'unverified';

    if ($action === 'suspend') {
        // Already suspended?
        if ($currentStatus === 'suspended') {
            Response::error('Deze gast is al geblokkeerd', 'ALREADY_SUSPENDED', 409);
        }
        $userModel->suspendUser($userId, $adminId, $reason);

        // Push notification (non-blocking)
        try {
            require_once __DIR__ . '/../../services/PushService.php';
            $pushService = new PushService($db);
            $pushService->sendNotification(
                $userId,
                $tenantId,
                'Account geblokkeerd',
                'Je account is geblokkeerd door de beheerder. Neem contact op met de bar voor meer informatie.'
            );
        } catch (\Throwable $e) {
            error_log('Push notification after suspend failed: ' . $e->getMessage());
        }

        // Audit log
        (new Audit($db))->log(
            $tenantId,
            $adminId,
            'admin.user_suspended',
            'user',
            $userId,
            ['reason' => $reason, 'status_before' => $currentStatus]
        );

        Response::success([
            'user_id'        => $userId,
            'account_status' => 'suspended',
            'action'         => 'suspend',
        ]);

    } else {
        // Unsuspend
        if ($currentStatus !== 'suspended') {
            Response::error('Deze gast is niet geblokkeerd', 'NOT_SUSPENDED', 409);
        }
        $userModel->unsuspendUser($userId, $adminId);

        // Audit log
        (new Audit($db))->log(
            $tenantId,
            $adminId,
            'admin.user_unsuspended',
            'user',
            $userId,
            ['status_before' => $currentStatus]
        );

        Response::success([
            'user_id'        => $userId,
            'account_status' => 'active',
            'action'         => 'unsuspend',
        ]);
    }

} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Actie mislukt: ' . $e->getMessage());
    }
    Response::internalError('Actie mislukt');
}
