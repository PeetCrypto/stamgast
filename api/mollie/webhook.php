<?php
declare(strict_types=1);

/**
 * POST /api/mollie/webhook
 * Mollie webhook handler — called by Mollie servers when payment status changes.
 *
 * NO AUTHENTICATION REQUIRED — Mollie calls this server-to-server.
 * The webhook receives a payment ID, fetches the status from Mollie API,
 * updates the platform fee from Mollie's authoritative applicationFee,
 * and credits the wallet if the payment is confirmed as "paid".
 *
 * Request body (from Mollie): { id: "tr_xxxxxxxx" }
 * Response: 200 OK (always, to prevent Mollie retries)
 */

require_once __DIR__ . '/../../services/WalletService.php';
require_once __DIR__ . '/../../services/MollieService.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../models/Transaction.php';
require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../models/PlatformSetting.php';
require_once __DIR__ . '/../../utils/helpers.php';

// Always return 200 to Mollie — we handle errors internally
function webhookRespond(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $code === 200, 'message' => $message]);
    exit;
}

if ($method !== 'POST') {
    webhookRespond(405, 'Method not allowed');
}

try {
    // Mollie sends form-encoded or JSON body with payment ID
    $input = [];
    try {
        $input = getJsonInput();
    } catch (\Throwable $jsonError) {
        // Not JSON — Mollie often sends form-encoded data
        $input = [];
    }
    if (empty($input)) {
        // Fallback: Mollie sometimes sends form-encoded data
        $paymentId = $_POST['id'] ?? null;
    } else {
        $paymentId = $input['id'] ?? null;
    }

    if (empty($paymentId) || !is_string($paymentId)) {
        webhookRespond(400, 'Missing payment ID');
    }

    $db = Database::getInstance()->getConnection();

    // Find the pending transaction by Mollie payment ID
    $transactionModel = new Transaction($db);
    $transaction = $transactionModel->findByMolliePaymentId($paymentId);

    if ($transaction === null) {
        // No matching transaction — could be a duplicate or unrelated webhook
        webhookRespond(200, 'Transaction not found for payment ID');
    }

    $transactionId = (int) $transaction['id'];
    $tenantId = (int) $transaction['tenant_id'];
    $userId   = (int) $transaction['user_id'];
    $amountCents = (int) $transaction['final_total_cents'];

    // Get tenant's Mollie configuration
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);

    if ($tenant === null) {
        webhookRespond(200, 'Tenant not found');
    }

    // ⚠️ IMPORTANT: Use the TENANT's OAuth access token to fetch payment status.
    // The payment was created with the tenant's token, so only that token can read it.
    // The platform API key cannot access tenant-scoped payments.
    $tenantAccessToken = $tenant['mollie_connect_access_token'] ?? '';
    $mollieMode = $tenant['mollie_status'] ?? 'mock';

    if ($mollieMode === 'mock') {
        // Mock mode: use platform key (mock doesn't call Mollie API)
        $platformApiKey = '';
        try {
            $ps = new PlatformSetting($db);
            $platformApiKey = $ps->get('mollie_connect_api_key') ?? '';
        } catch (\Throwable $e) {}
        if (empty($platformApiKey)) {
            $platformApiKey = defined('MOLLIE_CONNECT_API_KEY') ? MOLLIE_CONNECT_API_KEY : '';
        }
        $mollie = new MollieService($platformApiKey, 'mock');
    } elseif (empty($tenantAccessToken)) {
        // No tenant token — can't fetch payment status
        webhookRespond(200, 'Tenant has no Mollie access token');
    } else {
        // ── Proactive token refresh before API call ──────────────────────────
        $mollieApiKey = $tenantAccessToken;
        $tenantRefreshToken = $tenant['mollie_connect_refresh_token'] ?? '';

        if (!empty($tenantRefreshToken)) {
            try {
                $tenantModel = new Tenant($db);
                if ($tenantModel->isMollieTokenExpired($tenantId)) {
                    $ps = new PlatformSetting($db);
                    $refresher = new MollieService(
                        '', 'live',
                        $ps->get('mollie_connect_client_id'),
                        $ps->get('mollie_connect_client_secret')
                    );
                    $newTokens = $refresher->refreshAccessToken($tenantRefreshToken);

                    $tenantModel->updateMollieTokens(
                        $tenantId,
                        $newTokens['access_token'],
                        $newTokens['refresh_token'],
                        $newTokens['expires_at']
                    );

                    $mollieApiKey = $newTokens['access_token'];
                    error_log("Webhook: Mollie token auto-refreshed for tenant {$tenantId}");
                }
            } catch (\Throwable $e) {
                error_log("Webhook: Mollie token refresh failed for tenant {$tenantId}: " . $e->getMessage());
                // Continue with old token — getPaymentStatus may still work
            }
        }

        $mollie = new MollieService($mollieApiKey, $mollieMode);
    }

    $paymentStatus = $mollie->getPaymentStatus($paymentId);

    // Log the webhook event
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $userId,
        'mollie.webhook_received',
        'transaction',
        $transactionId,
        [
            'mollie_payment_id' => $paymentId,
            'status'            => $paymentStatus['status'],
            'amount_cents'      => $amountCents,
        ]
    );

    // ═══════════════════════════════════════════════════════════════════
    // UPDATE TRANSACTION STATUS FROM MOLLIE
    // Always update — only 'paid' triggers processDeposit() below
    // Failed/expired/cancelled are visible in history but NOT in wallet
    // ═══════════════════════════════════════════════════════════════════
    $mollieStatus = $paymentStatus['status'] ?? 'open';
    $statusMap = [
        'open'      => 'pending',
        'pending'   => 'pending',
        'paid'      => 'paid',
        'failed'    => 'failed',
        'expired'   => 'expired',
        'cancelled' => 'cancelled',
    ];
    $txStatus = $statusMap[$mollieStatus] ?? 'pending';
    $transactionModel->updateStatus($transactionId, $txStatus);

    // ═══════════════════════════════════════════════════════════════════
    // CRITICAL: Update platform fee FROM MOLLIE AS AUTHORITY
    // ═══════════════════════════════════════════════════════════════════
    $platformFeeModel = new PlatformFee($db);
    $platformFee = $platformFeeModel->findByTransactionId($transactionId);

    if ($platformFee) {
        // Extract authoritative fee from Mollie Connect response
        $applicationFeeCents = (int) ($paymentStatus['application_fee_cents'] ?? 0);
        $mollieFeeCents     = (int) ($paymentStatus['mollie_fee_cents'] ?? 0);

        // Fallback: if Mollie returns 0 applicationFee (e.g. webhook without settlement data,
        // or onBehalfOf not set), use the server-side calculated fee from the snapshot.
        // The snapshot (fee_percentage + fee_min_cents) was stored at deposit creation time.
        if ($applicationFeeCents === 0 && (int) $platformFee['fee_amount_cents'] > 0) {
            $applicationFeeCents = (int) $platformFee['fee_amount_cents'];
        }

        $platformFeeModel->updateFeeFromMollie(
            $platformFee['id'],
            $applicationFeeCents,
            $mollieFeeCents
        );

        // Audit fee update
        $audit->log(
            $tenantId,
            $userId,
            'platform_fee.updated_from_mollie',
            'platform_fee',
            $platformFee['id'],
            [
                'mollie_payment_id'    => $paymentId,
                'application_fee_cents' => $applicationFeeCents,
                'mollie_fee_cents'      => $mollieFeeCents,
                'gross_amount_cents'    => $amountCents,
            ]
        );
    }

    // Process only if payment is confirmed as "paid"
    if ($mollie->isPaid($paymentStatus['status'])) {
        $walletService = new WalletService($db);

        try {
            $processed = $walletService->processDeposit($paymentId, $amountCents);

            if ($processed) {
                // Log successful deposit
                $audit->log(
                    $tenantId,
                    $userId,
                    'wallet.deposit_completed',
                    'transaction',
                    $transactionId,
                    [
                        'mollie_payment_id' => $paymentId,
                        'amount_cents'      => $amountCents,
                    ]
                );
            } else {
                // Already processed (idempotent)
                $audit->log(
                    $tenantId,
                    $userId,
                    'wallet.deposit_duplicate_webhook',
                    'transaction',
                    $transactionId,
                    ['mollie_payment_id' => $paymentId]
                );
            }
        } catch (\Throwable $e) {
            // Log failure but still return 200 to Mollie
            $audit->log(
                $tenantId,
                $userId,
                'wallet.deposit_failed',
                'transaction',
                $transactionId,
                ['error' => $e->getMessage()]
            );
        }
    }

    webhookRespond(200, 'Webhook processed');

} catch (\Throwable $e) {
    // Always return 200 to prevent Mollie retry storms
    error_log('Webhook error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    webhookRespond(200, 'Webhook received with internal error');
}
