<?php
declare(strict_types=1);

/**
 * POST /api/guest/scan_payment
 * Guest scans the bartender's POS QR code.
 * Returns the payment details (amounts, discounts) for the guest to confirm.
 *
 * Auth: guest+ (any authenticated user)
 * Middleware: CSRF (enforced by router)
 *
 * Request:  { qr_payload: string }
 * Response: { session_id, session_token, amount_alc_cents, amount_food_cents, discount_alc_cents, discount_food_cents, final_total_cents, balance_cents, guest_name }
 */

require_once __DIR__ . '/../../services/QrService.php';
require_once __DIR__ . '/../../models/PaymentSession.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Wallet.php';
require_once __DIR__ . '/../../models/Transaction.php';
require_once __DIR__ . '/../../models/LoyaltyTier.php';
require_once __DIR__ . '/../../models/Tenant.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$qrPayload = trim($input['qr_payload'] ?? '');

if ($qrPayload === '') {
    Response::error('QR payload is vereist', 'VALIDATION_ERROR', 422);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();

    // Step 1: Validate the POS QR code
    $qrService = new QrService($db);
    $validation = $qrService->validatePosQr($qrPayload);

    if (!$validation['valid']) {
        Response::error($validation['error'], 'INVALID_QR', 400);
    }

    // Step 2: Verify tenant match (only for old long format with embedded tenant_id)
    // For new short format, tenant_id is null — tenant is verified via session lookup in Step 3
    if ($validation['tenant_id'] !== null && $validation['tenant_id'] !== $tenantId) {
        Response::error('QR code behoort niet tot deze locatie', 'TENANT_MISMATCH', 400);
    }

    // Step 3: Find the payment session
    $sessionModel = new PaymentSession($db);
    $session = $sessionModel->findByTokenAndTenant($validation['session_token'], $tenantId);

    if ($session === null) {
        // Debug: help diagnose why session not found
        $debugInfo = '';
        if (APP_DEBUG) {
            $debugInfo = ' [debug: token=' . substr($validation['session_token'], 0, 8) . '..., tenant=' . $tenantId . ']';
        }
        Response::error('Betalingssessie niet gevonden' . $debugInfo, 'NOT_FOUND', 404);
    }

    // Step 4: Check session is still valid
    if (!$sessionModel->isValid($session)) {
        $status = $session['status'];
        if ($status === 'expired') {
            Response::error('Deze betalingssessie is verlopen', 'SESSION_EXPIRED', 400);
        }
        Response::error('Deze betalingssessie is niet meer geldig (status: ' . $status . ')', 'SESSION_INVALID', 400);
    }

    // Step 5: Check guest account status
    $userModel = new User($db);
    $user = $userModel->findById($userId);

    if ($user === null || (int) $user['tenant_id'] !== $tenantId) {
        Response::error('Gebruiker niet gevonden binnen deze locatie', 'USER_NOT_FOUND', 404);
    }

    $accountStatus = $user['account_status'] ?? 'unverified';
    if ($accountStatus === 'suspended') {
        Response::error('Je account is geblokkeerd door de beheerder', 'ACCOUNT_SUSPENDED', 403);
    }
    if ($accountStatus !== 'active') {
        Response::error('Je account is nog niet geverifieerd. Laat je ID zien bij de bar.', 'NOT_VERIFIED', 403);
    }

    // Step 6: Calculate discounts server-side
    $transactionModel = new Transaction($db);
    $totalDeposits = $transactionModel->getTotalDeposits($userId, $tenantId);

    $tierModel = new LoyaltyTier($db);
    $tier = $tierModel->determineTier($tenantId, $totalDeposits);

    $isBonusModel = ($tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT) === LoyaltyTier::MODEL_BONUS;

    $alcDiscountPerc = $isBonusModel
        ? 0
        : min((float) $tier['alcohol_discount_perc'], (float) ALCOHOL_DISCOUNT_MAX);
    $foodDiscountPerc = min((float) $tier['food_discount_perc'], (float) FOOD_DISCOUNT_MAX);

    $alcCents  = (int) $session['amount_alc_cents'];
    $foodCents = (int) $session['amount_food_cents'];

    $discountAlc  = (int) floor($alcCents * $alcDiscountPerc / 100);
    $discountFood = (int) floor($foodCents * $foodDiscountPerc / 100);
    $finalTotal   = ($alcCents - $discountAlc) + ($foodCents - $discountFood);

    // Step 7: Mark session as scanned — link guest to session
    $guestName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $sessionModel->markScanned(
        (int) $session['id'],
        $userId,
        $guestName,
        $discountAlc,
        $discountFood,
        $finalTotal
    );

    // Step 8: Get wallet balance
    $walletModel = new Wallet($db);
    $wallet = $walletModel->findByUserAndTenant($userId, $tenantId);
    $balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;

    // Step 9: Get tenant name + tip options (for new short QR format that doesn't embed it)
    $tenantName = $validation['tenant_name'] ?? '';
    $tenantModel = new Tenant($db);
    $tenantRow = $tenantModel->findById($tenantId);
    if (empty($tenantName)) {
        $tenantName = $tenantRow ? ($tenantRow['name'] ?? '') : '';
    }

    // Step 10: Get tip options from tenant config
    $tipOptions = [
        (int) ($tenantRow['tip_amount_1_cents'] ?? 100),
        (int) ($tenantRow['tip_amount_2_cents'] ?? 250),
        (int) ($tenantRow['tip_amount_3_cents'] ?? 500),
    ];
    // Filter out zero amounts (disabled tips)
    $tipOptions = array_values(array_filter($tipOptions, function($v) { return $v > 0; }));

    // Audit
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $userId,
        'guest.session_scanned',
        'pos_payment_session',
        (int) $session['id'],
        ['final_total_cents' => $finalTotal]
    );

    Response::success([
        'session_id'          => (int) $session['id'],
        'session_token'       => $session['session_token'],
        'tenant_name'         => $tenantName,
        'amount_alc_cents'    => $alcCents,
        'amount_food_cents'   => $foodCents,
        'discount_alc_cents'  => $discountAlc,
        'discount_food_cents' => $discountFood,
        'final_total_cents'   => $finalTotal,
        'balance_cents'       => $balanceCents,
        'sufficient_balance'  => $balanceCents >= $finalTotal,
        'guest_name'          => $guestName,
        'tier_name'           => $tier['name'],
        'tip_options_cents'   => $tipOptions,
    ]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('QR scannen mislukt: ' . $e->getMessage());
    }
    Response::internalError('QR scannen mislukt');
}
