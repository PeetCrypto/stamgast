<?php
declare(strict_types=1);

/**
 * Wallet Service
 * Handles wallet operations: deposit, balance checks, deposit creation via Mollie
 */

require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/LoyaltyTier.php';
require_once __DIR__ . '/MollieService.php';

class WalletService
{
    private PDO $db;
    private Wallet $walletModel;
    private Transaction $transactionModel;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->walletModel = new Wallet($db);
        $this->transactionModel = new Transaction($db);
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
     * Create a deposit via Mollie
     * Returns the checkout URL for the guest to complete payment
     *
     * @return array{checkout_url: string, payment_id: string}
     * @throws \InvalidArgumentException on invalid input
     * @throws \RuntimeException on Mollie or DB error
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

        // Get tenant Mollie settings
        $tenantModel = new Tenant($this->db);
        $tenant = $tenantModel->findById($tenantId);
        if ($tenant === null) {
            throw new \RuntimeException('Tenant niet gevonden');
        }

        // Build redirect/webhook URLs
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = "{$scheme}://{$host}";

        $redirectUrl = $baseUrl . '/wallet';
        $webhookUrl = $baseUrl . '/api/mollie/webhook';

        // Create Mollie payment
        $mollie = new MollieService(
            $tenant['mollie_api_key'] ?? '',
            $tenant['mollie_status'] ?? 'mock'
        );

        $payment = $mollie->createPayment(
            $amountCents,
            'Opwaarderen STAMGAST wallet',
            $redirectUrl,
            $webhookUrl,
            (string) $userId
        );

        // Create pending transaction
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

        return [
            'checkout_url'   => $payment['checkout_url'],
            'payment_id'     => $payment['payment_id'],
            'transaction_id' => $transactionId,
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

        // Already processed (idempotency check)
        if ($transaction['type'] !== 'deposit' || $transaction['final_total_cents'] === 0) {
            return false;
        }

        $userId = (int) $transaction['user_id'];
        $tenantId = (int) $transaction['tenant_id'];

        // Atomic: credit wallet
        try {
            $this->db->beginTransaction();

            // Credit wallet balance
            $this->walletModel->updateBalance($userId, $amountCents, 0);

            $this->db->commit();
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
