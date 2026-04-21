<?php
declare(strict_types=1);

/**
 * POST /api/mollie/webhook
 * Mollie webhook handler — called by Mollie servers when payment status changes.
 *
 * NO AUTHENTICATION REQUIRED — Mollie calls this server-to-server.
 * The webhook receives a payment ID, fetches the status from Mollie API,
 * and credits the wallet if the payment is confirmed as "paid".
 *
 * Request body (from Mollie): { id: "tr_xxxxxxxx" }
 * Response: 200 OK (always, to prevent Mollie retries)
 */

require_once __DIR__ . '/../../services/WalletService.php';
require_once __DIR__ . '/../../services/MollieService.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../models/Transaction.php';

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
    $input = getJsonInput();
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

    $tenantId = (int) $transaction['tenant_id'];
    $userId   = (int) $transaction['user_id'];
    $amountCents = (int) $transaction['final_total_cents'];

    // Get tenant's Mollie configuration
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);

    if ($tenant === null) {
        webhookRespond(200, 'Tenant not found');
    }

    // Fetch payment status from Mollie
    $mollie = new MollieService(
        $tenant['mollie_api_key'] ?? '',
        $tenant['mollie_status'] ?? 'mock'
    );

    $paymentStatus = $mollie->getPaymentStatus($paymentId);

    // Log the webhook event
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $userId,
        'mollie.webhook_received',
        'transaction',
        (int) $transaction['id'],
        [
            'mollie_payment_id' => $paymentId,
            'status'            => $paymentStatus['status'],
            'amount_cents'      => $amountCents,
        ]
    );

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
                    (int) $transaction['id'],
                    [
                        'mollie_payment_id' => $paymentId,
                        'amount_cents'      => $amountCents,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Log failure but still return 200 to Mollie
            $audit->log(
                $tenantId,
                $userId,
                'wallet.deposit_failed',
                'transaction',
                (int) $transaction['id'],
                ['error' => $e->getMessage()]
            );
        }
    }

    webhookRespond(200, 'Webhook processed');

} catch (\Throwable $e) {
    // Always return 200 to prevent Mollie retry storms
    webhookRespond(200, 'Webhook received with internal error');
}
