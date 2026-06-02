<?php
declare(strict_types=1);

/**
 * GET /api/pos/session_status
 * Bartender polls the session status to detect when guest has paid.
 *
 * Auth: bartender+ (enforced by router)
 * Middleware: CSRF, IP whitelist (enforced by router)
 *
 * Request:  ?session_token=xxx
 * Response: { status, guest_name, final_total_cents, transaction_id, error_message, tier?: {name, model_type, alcohol_discount, food_discount, points_multiplier} }
 */

require_once __DIR__ . '/../../models/PaymentSession.php';
require_once __DIR__ . '/../../models/Transaction.php';
require_once __DIR__ . '/../../models/LoyaltyTier.php';
require_once __DIR__ . '/../../models/Tenant.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$sessionToken = trim($_GET['session_token'] ?? '');

if ($sessionToken === '') {
    Response::error('session_token is vereist', 'VALIDATION_ERROR', 422);
}

$tenantId = currentTenantId();
if ($tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $sessionModel = new PaymentSession($db);

    $session = $sessionModel->findByTokenAndTenant($sessionToken, $tenantId);

    if ($session === null) {
        Response::error('Sessie niet gevonden', 'NOT_FOUND', 404);
    }

    // Auto-expire if past expiry time
    $status = $session['status'];
    if ($status === 'pending' && strtotime($session['expires_at']) < time()) {
        $sessionModel->markFailed((int) $session['id'], 'Verlopen');
        $status = 'expired';
    }

    // Get tier information from the associated transaction (if any)
    $tierInfo = null;
    if (!empty($session['transaction_id'])) {
        $transactionModel = new Transaction($db);
        $transaction = $transactionModel->findById((int) $session['transaction_id'], $tenantId);
        if ($transaction) {
            $userId = (int) $transaction['user_id'];
            // Compute tier for this user based on total deposits
            $totalDeposits = $transactionModel->getTotalDeposits($userId, $tenantId);
            $tierModel = new LoyaltyTier($db);
            $tier = $tierModel->determineTier($tenantId, $totalDeposits);
            if ($tier) {
                $tierInfo = [
                    'name' => $tier['name'],
                    'model_type' => $tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT,
                    'alcohol_discount' => (float) ($tier['alcohol_discount_perc'] ?? 0),
                    'food_discount' => (float) ($tier['food_discount_perc'] ?? 0),
                    'points_multiplier' => (float) ($tier['points_multiplier'] ?? 1.0),
                ];
            }
        }
    }

    Response::success([
        'status'            => $status,
        'guest_name'        => $session['guest_name'],
        'final_total_cents' => (int) $session['final_total_cents'],
        'tip_cents'         => (int) ($session['tip_cents'] ?? 0),
        'transaction_id'    => $session['transaction_id'] ? (int) $session['transaction_id'] : null,
        'error_message'     => $session['error_message'],
        'tier'              => $tierInfo,
    ]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Status ophalen mislukt: ' . $e->getMessage());
    }
    Response::internalError('Status ophalen mislukt');
}
