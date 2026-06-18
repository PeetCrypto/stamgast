<?php
declare(strict_types=1);

/**
 * POST /api/wallet/deposit
 * Create a deposit request via Mollie payment provider.
 * Returns the Mollie checkout URL for the guest to complete payment.
 *
 * In mock mode, the checkout URL redirects directly to the wallet page
 * with a simulated payment success parameter.
 *
 * Auth: guest+ (any authenticated user)
 *
 * Request:  { amount_cents: int, tier_id?: int }
 * Response: { checkout_url: string, payment_id: string, transaction_id: int }
 */

require_once __DIR__ . '/../../services/WalletService.php';
require_once __DIR__ . '/../../models/User.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$amountCents = (int) ($input['amount_cents'] ?? 0);
$tierId    = !empty($input['tier_id']) ? (int) $input['tier_id'] : null;

if ($amountCents <= 0) {
    Response::error('Bedrag moet groter zijn dan 0', 'VALIDATION_ERROR', 422);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

// Gated onboarding: only active accounts can deposit
// Staff (admin, bartender) are always active — only guests need verification
$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$user = $userModel->findById($userId);
if ($user && $user['role'] === 'guest' && ($user['account_status'] ?? 'unverified') !== 'active') {
    Response::error(
        'Je account moet eerst geactiveerd worden door de barman voordat je kunt opwaarderen',
        'ACCOUNT_NOT_ACTIVE',
        403
    );
}

try {
    $walletService = new WalletService($db);

    $result = $walletService->createDeposit($userId, $tenantId, $amountCents, $tierId);

    // Log the deposit initiation
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $userId,
        'wallet.deposit_initiated',
        'transaction',
        $result['transaction_id'],
        [
            'amount_cents'     => $amountCents,
            'mollie_payment_id' => $result['payment_id'],
        ]
    );

    Response::success($result);
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 'VALIDATION_ERROR', 422);
} catch (\RuntimeException $e) {
    // ⚠️ ALWAYS log the real error so it's never hidden in production.
    error_log('[deposit] RuntimeException for tenant ' . ($tenantId ?? '?') .
              ' user ' . ($userId ?? '?') . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    // Superadmin sees the real error in the console for fast debugging;
    // guests keep the safe generic message (no info leak).
    $showDetail = APP_DEBUG || currentUserRole() === 'superadmin';
    Response::internalError($showDetail ? ('Opwaardering mislukt: ' . $e->getMessage()) : 'Opwaardering mislukt');
} catch (\Throwable $e) {
    // ⚠️ ALWAYS log the real error so it's never hidden in production.
    error_log('[deposit] Throwable for tenant ' . ($tenantId ?? '?') .
              ' user ' . ($userId ?? '?') . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $showDetail = APP_DEBUG || currentUserRole() === 'superadmin';
    Response::internalError($showDetail ? ('Opwaardering mislukt: ' . $e->getMessage()) : 'Opwaardering mislukt');
}
