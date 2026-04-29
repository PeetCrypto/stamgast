<?php
declare(strict_types=1);

/**
 * POST /api/pos/scan
 * Scan a guest's QR code, validate HMAC signature, and return user info.
 *
 * Auth: bartender+ (enforced by router)
 * Middleware: CSRF, IP whitelist (enforced by router)
 *
 * Request:  { qr_payload: string }
 * Response: { valid: true, user: { id, name, photo_url, photo_status, age, tier } }
 * Error:    { valid: false, error: string }
 */

require_once __DIR__ . '/../../services/QrService.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Wallet.php';
require_once __DIR__ . '/../../models/Transaction.php';
require_once __DIR__ . '/../../models/LoyaltyTier.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$qrPayload = trim($input['qr_payload'] ?? '');

if ($qrPayload === '') {
    Response::error('QR payload is vereist', 'VALIDATION_ERROR', 422);
}

$tenantId = currentTenantId();
if ($tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();

    // Step 1: Validate QR code (HMAC + expiry check)
    $qrService = new QrService($db);
    $validation = $qrService->validate($qrPayload);

    if (!$validation['valid']) {
        // Log failed scan attempt
        $audit = new Audit($db);
        $audit->log(
            $tenantId,
            currentUserId(),
            'pos.scan_failed',
            null,
            null,
            ['error' => $validation['error']]
        );

        Response::success([
            'valid' => false,
            'error' => $validation['error'],
        ]);
    }

    $scannedUserId = $validation['user_id'];
    $scannedTenantId = $validation['tenant_id'];

    // Step 2: Verify tenant match (cross-tenant QR = rejected)
    if ($scannedTenantId !== $tenantId) {
        $audit = new Audit($db);
        $audit->log(
            $tenantId,
            currentUserId(),
            'pos.scan_tenant_mismatch',
            'user',
            $scannedUserId,
            ['scanned_tenant_id' => $scannedTenantId]
        );

        Response::success([
            'valid' => false,
            'error' => 'QR code behoort niet tot deze locatie',
        ]);
    }

    // Step 3: Fetch user profile (safe data only)
    $userModel = new User($db);
    $user = $userModel->getPublicProfile($scannedUserId);

    if ($user === null) {
        Response::success([
            'valid' => false,
            'error' => 'Gebruiker niet gevonden',
        ]);
    }

    // Step 4: Calculate age from birthdate
    $age = $userModel->calculateAge($scannedUserId);

    // Step 5: Determine loyalty tier
    $transactionModel = new Transaction($db);
    $totalDeposits = $transactionModel->getTotalDeposits($scannedUserId, $tenantId);

    $tierModel = new LoyaltyTier($db);
    $tier = $tierModel->determineTier($tenantId, $totalDeposits);

    // Step 5b: Get wallet balance for POS display
    $walletModel = new Wallet($db);
    $wallet = $walletModel->findByUserAndTenant($scannedUserId, $tenantId);
    $balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;

    // Step 6: Log successful scan
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        currentUserId(),
        'pos.scan_success',
        'user',
        $scannedUserId,
        ['tier' => $tier['name']]
    );

    // Return user info for POS display
    Response::success([
        'valid' => true,
        'user'  => [
            'id'           => (int) $user['id'],
            'name'         => $user['first_name'] . ' ' . $user['last_name'],
            'photo_url'    => $user['photo_url'],
            'photo_status' => $user['photo_status'],
            'account_status' => $user['account_status'] ?? 'unverified',
            'age'          => $age,
            'wallet'       => [
                'balance_cents' => $balanceCents,
            ],
            'tier'         => [
                'name'              => $tier['name'],
                'alcohol_discount'  => (float) $tier['alcohol_discount_perc'],
                'food_discount'     => (float) $tier['food_discount_perc'],
                'points_multiplier' => (float) $tier['points_multiplier'],
            ],
        ],
    ]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('QR scan mislukt: ' . $e->getMessage());
    }
    Response::internalError('QR scan mislukt');
}
