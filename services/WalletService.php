<?php
declare(strict_types=1);

/**
 * Wallet Service
 * Handles wallet operations: deposit, balance checks, deposit creation via Mollie
 *
 * PLATFORM FEE INTEGRATION:
 * - Fee is calculated SERVER-SIDE at deposit creation time
 * - Hard fail if tenant has no active Mollie Connect
 * - Platform fee sent to Mollie as applicationFee (automatic split)
 * - Fee record created with SNAPSHOT of percentage/min
 * - Webhook updates fee amount from Mollie's authoritative value
 */

require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/LoyaltyTier.php';
require_once __DIR__ . '/../models/PlatformFee.php';
require_once __DIR__ . '/../models/PlatformSetting.php';
require_once __DIR__ . '/MollieService.php';

class WalletService
{
    private PDO $db;
    private Wallet $walletModel;
    private Transaction $transactionModel;
    private Tenant $tenantModel;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->walletModel = new Wallet($db);
        $this->transactionModel = new Transaction($db);
        $this->tenantModel = new Tenant($db);
    }

    /**
     * Get platform Mollie API key from DB (platform_settings) or fall back to constant
     */
    private function getPlatformApiKey(): string
    {
        try {
            $ps = new PlatformSetting($this->db);
            $key = $ps->get('mollie_connect_api_key');
            if (!empty($key)) {
                return $key;
            }
        } catch (\Throwable $e) {
            error_log('WalletService::getPlatformApiKey - DB read failed: ' . $e->getMessage());
        }
        return defined('MOLLIE_CONNECT_API_KEY') ? MOLLIE_CONNECT_API_KEY : '';
    }

    /**
     * Calculate platform fee
     *
     * @param int   $amountCents  Gross deposit amount
     * @param float $percentage   Fee percentage (e.g. 1.00 for 1%)
     * @param int   $minCents     Minimum fee in cents
     * @return int  Fee in cents
     */
    private function calculateFee(int $amountCents, float $percentage, int $minCents): int
    {
        $calculated = (int) floor($amountCents * $percentage / 100);
        return max($calculated, $minCents);
    }

    /**
     * Get wallet balance + active tier info for a user
     *
     * @return array{balance_cents: int, points_cents: int, tier: array|null}
     */
    public function getBalance(int $userId, int $tenantId): array
    {
        $wallet = $this->walletModel->findByUserAndTenant($userId, $tenantId);

        if ($wallet === null) {
            return [
                'balance_cents' => 0,
                'points_cents'  => 0,
                'tier'          => null,
            ];
        }

        // Determine tier
        $totalDeposits = $this->transactionModel->getTotalDeposits($userId, $tenantId);
        $tierModel = new LoyaltyTier($this->db);
        $tier = $tierModel->determineTier($tenantId, $totalDeposits);

        return [
            'balance_cents' => (int) $wallet['balance_cents'],
            'points_cents'  => (int) $wallet['points_cents'],
            'tier'          => [
                'name'              => $tier['name'],
                'points_multiplier' => (float) $tier['points_multiplier'],
            ],
        ];
    }

    /**
     * Create a deposit via Mollie (with platform fee)
     * Returns the checkout URL for the guest to complete payment
     *
     * Modes:
     * - mock:  No Mollie Connect required. Payment simulated instantly, wallet credited immediately.
     * - test:  Mollie Connect required. Uses Mollie test API keys.
     * - live:  Mollie Connect required. Uses Mollie live API keys.
     *
     * @throws \InvalidArgumentException on invalid input or Connect not active (test/live only)
     * @throws \RuntimeException on Mollie/DB error
     * @return array{checkout_url: string, payment_id: string, transaction_id: int, fee_cents: int, status?: string}
     */
    public function createDeposit(int $userId, int $tenantId, int $amountCents): array
    {
        // Validate amount
        if ($amountCents < DEPOSIT_MIN_CENTS) {
            throw new \InvalidArgumentException(
                'Minimum opwaardering is €' . centsToEuro(DEPOSIT_MIN_CENTS)
            );
        }
        if ($amountCents > DEPOSIT_MAX_CENTS) {
            throw new \InvalidArgumentException(
                'Maximum opwaardering is €' . centsToEuro(DEPOSIT_MAX_CENTS)
            );
        }

        // Gated onboarding: only active users can deposit
        $userModel = new \User($this->db);
        $accountStatus = $userModel->getAccountStatus($userId);
        if ($accountStatus !== 'active') {
            throw new \InvalidArgumentException(
                'Je account is nog niet geactiveerd. Laat je ID zien bij de bar.'
            );
        }

        // Get tenant
        $tenant = $this->tenantModel->findById($tenantId);
        if ($tenant === null) {
            throw new \RuntimeException('Tenant niet gevonden');
        }

        $mollieMode = $tenant['mollie_status'] ?? 'mock';
        $isMock = ($mollieMode === 'mock');

        // ⚠️ HARD FAIL: Mollie Connect must be active (test/live only)
        // In mock mode, no Mollie Connect is needed — payments are simulated
        if (!$isMock && !($this->tenantModel->isConnectActive($tenantId))) {
            throw new \InvalidArgumentException(
                'Tenant heeft geen actieve Mollie Connect account. ' .
                'Een superadmin moet de tenant eerst koppelen via Mollie Connect.'
            );
        }

        // Calculate platform fee (snapshot at creation time)
        $feeCents = $this->calculateFee(
            $amountCents,
            (float) $tenant['platform_fee_percentage'],
            (int) $tenant['platform_fee_min_cents']
        );

        // Build redirect/webhook URLs
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = "{$scheme}://{$host}";

        $redirectUrl = $baseUrl . '/wallet';
        $webhookUrl = $baseUrl . '/api/mollie/webhook';

        // Get platform API key: try DB first, fall back to constant
        $platformApiKey = $this->getPlatformApiKey();
        if (!$isMock && empty($platformApiKey)) {
            throw new \RuntimeException('Platform Mollie Connect API key niet geconfigureerd. Configureer via Superadmin > Platform Instellingen.');
        }

        $mollie = new MollieService($platformApiKey, $mollieMode);

        $payment = $mollie->createPayment(
            $amountCents,
            'Opwaarderen REGULR.vip wallet',
            $redirectUrl,
            $webhookUrl,
            (string) $userId,
            $isMock ? null : $tenant['mollie_connect_id'], // onBehalfOf: only for real Mollie
            $isMock ? 0 : $feeCents                        // applicationFee: only for real Mollie
        );

        // Create pending transaction (deposit)
        $transactionId = $this->transactionModel->create([
            'tenant_id'          => $tenantId,
            'user_id'            => $userId,
            'bartender_id'       => null,
            'type'               => 'deposit',
            'final_total_cents'  => $amountCents,
            'ip_address'         => getClientIP(),
            'mollie_payment_id'  => $payment['payment_id'],
            'description'        => 'Opwaardering wallet',
        ]);

        // Create PlatformFee record
        $platformFeeId = (new PlatformFee($this->db))->create([
            'tenant_id'           => $tenantId,
            'transaction_id'      => $transactionId,
            'mollie_payment_id'   => $payment['payment_id'],
            'user_id'             => $userId,
            'gross_amount_cents'  => $amountCents,
            'fee_percentage'      => $tenant['platform_fee_percentage'], // SNAPSHOT
            'fee_amount_cents'    => $isMock ? $feeCents : 0, // Mock: use calculated fee; Real: filled by webhook
            'net_amount_cents'    => $isMock ? ($amountCents - $feeCents) : 0,
            'fee_min_cents'       => $tenant['platform_fee_min_cents'],  // SNAPSHOT
            'status'              => 'collected',
        ]);

        // ═══════════════════════════════════════════════════════════════
        // MOCK MODE: Auto-process deposit immediately (no webhook needed)
        // ═══════════════════════════════════════════════════════════════
        if ($isMock) {
            $this->processDeposit($payment['payment_id'], $amountCents);

            // Audit log
            $audit = new Audit($this->db);
            $audit->log(
                $tenantId,
                $userId,
                'wallet.deposit_mock_completed',
                'transaction',
                $transactionId,
                [
                    'amount_cents'      => $amountCents,
                    'fee_cents'         => $feeCents,
                    'mollie_payment_id' => $payment['payment_id'],
                ]
            );

            // Send notification + email to guest (non-critical)
            try {
                require_once __DIR__ . '/NotificationService.php';
                $notifService = new NotificationService($this->db);
                $notifService->notifyTransaction(
                    $userId, $tenantId, $transactionId, 'deposit', $amountCents,
                    ['description' => 'Opwaardering wallet']
                );
            } catch (\Throwable $e) {
                error_log('Notification after deposit failed: ' . $e->getMessage());
            }

            return [
                'checkout_url'   => null,
                'payment_id'     => $payment['payment_id'],
                'transaction_id' => $transactionId,
                'fee_cents'      => $feeCents,
                'status'         => 'mock',
            ];
        }

        // Audit log (test/live)
        $audit = new Audit($this->db);
        $audit->log(
            $tenantId,
            $userId,
            'wallet.deposit_initiated',
            'transaction',
            $transactionId,
            [
                'amount_cents'       => $amountCents,
                'fee_cents'          => $feeCents,
                'fee_percentage'     => $tenant['platform_fee_percentage'],
                'fee_min_cents'      => $tenant['platform_fee_min_cents'],
                'mollie_payment_id'  => $payment['payment_id'],
                'connected_org_id'   => $tenant['mollie_connect_id'],
            ]
        );

        return [
            'checkout_url'   => $payment['checkout_url'],
            'payment_id'     => $payment['payment_id'],
            'transaction_id' => $transactionId,
            'fee_cents'      => $feeCents,
        ];
    }

    /**
     * Process a completed deposit (called by webhook)
     * Credits the wallet for a confirmed Mollie payment
     *
     * @return bool True if deposit was processed, false if already processed
     * @throws \RuntimeException on DB error
     */
    public function processDeposit(string $molliePaymentId, int $amountCents): bool
    {
        // Find the pending transaction
        $transaction = $this->transactionModel->findByMolliePaymentId($molliePaymentId);

        if ($transaction === null) {
            throw new \RuntimeException('Transactie niet gevonden voor payment ID: ' . $molliePaymentId);
        }

        $transactionId = (int) $transaction['id'];
        $userId = (int) $transaction['user_id'];
        $tenantId = (int) $transaction['tenant_id'];

        // Idempotency check: has this deposit already been processed?
        $feeModel = new PlatformFee($this->db);
        $fee = $feeModel->findByTransactionId($transactionId);

        if ($fee && $fee['deposit_processed_at'] !== null) {
            // Already credited
            return false;
        }

        if ($fee === null) {
            throw new \RuntimeException('Platform fee record niet gevonden voor transaction ID: ' . $transactionId);
        }

        // Atomic: credit wallet balance (GROSS amount — guest gets full value)
        try {
            $this->db->beginTransaction();

            // Credit wallet
            $this->walletModel->updateBalance($userId, $amountCents, 0);

            // Mark platform fee as deposit processed (idempotency guard)
            $stmt = $this->db->prepare('UPDATE `platform_fees` SET `deposit_processed_at` = NOW() WHERE `id` = :id');
            $stmt->execute([':id' => $fee['id']]);

            $this->db->commit();

            // Send notification + email to guest (non-critical)
            try {
                require_once __DIR__ . '/NotificationService.php';
                $notifService = new NotificationService($this->db);
                $notifService->notifyTransaction(
                    $userId, $tenantId, $transactionId, 'deposit', $amountCents,
                    ['description' => 'Opwaardering wallet']
                );
            } catch (\Throwable $e) {
                error_log('Notification after deposit failed: ' . $e->getMessage());
            }

            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Deposit verwerking mislukt: ' . $e->getMessage());
        }
    }

    /**
     * Get transaction history for a user (paginated)
     *
     * @return array{transactions: array, total: int, page: int, limit: int}
     */
    public function getHistory(int $userId, int $tenantId, int $page = 1, int $limit = 20): array
    {
        return $this->transactionModel->getByUser($userId, $tenantId, $page, $limit);
    }
}
