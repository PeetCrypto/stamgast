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

        // Check if points are enabled for this tenant
        $tenant = $this->tenantModel->findById($tenantId);
        $pointsEnabled = (bool) ($tenant['points_enabled'] ?? true);

        return [
            'balance_cents' => (int) $wallet['balance_cents'],
            'points_cents'  => $pointsEnabled ? (int) $wallet['points_cents'] : 0,
            'tier'          => $pointsEnabled ? [
                'name'              => $tier['name'],
                'points_multiplier' => (float) $tier['points_multiplier'],
            ] : null,
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
    public function createDeposit(int $userId, int $tenantId, int $amountCents, ?int $tierId = null): array
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
        // Support ngrok/proxy: use X-Forwarded-Host if available (ngrok sends the public URL)
        $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!empty($forwardedHost)) {
            $scheme = !empty($forwardedProto) ? $forwardedProto : 'https';
            $host = $forwardedHost;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }
        $baseUrl = "{$scheme}://{$host}";

        // Redirect back to wallet with from_payment flag so the frontend knows to
        // poll for balance updates (race condition: webhook may not have processed yet)
        $redirectUrl = $baseUrl . '/wallet?from_payment=1';
        $webhookUrl = $baseUrl . '/api/mollie/webhook';

        // Local dev (.test/.local/localhost): Mollie can't reach local URLs.
        // Omit webhookUrl — Mollie accepts payments without it (webhookUrl is optional).
        // On production, webhookUrl is always included for automatic payment confirmation.
        $actualHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $isLocal = preg_match('/\.(test|local)$/', $actualHost) || $actualHost === 'localhost' || $actualHost === '127.0.0.1';
        if ($isLocal && empty($forwardedHost)) {
            $webhookUrl = null;
        }

        // Get the Mollie API key for payment creation.
        // For Connect payments (test/live), we use the tenant's OAuth access token.
        // With an OAuth access token:
        //   - Do NOT send onBehalfOf (token is already scoped to the tenant's org)
        //   - DO send profileId (required by Mollie)
        //   - DO send applicationFee (works with OAuth tokens)
        $platformApiKey = $this->getPlatformApiKey();
        $mollieApiKey = $platformApiKey;
        if (!$isMock) {
            $tenantAccessToken = $tenant['mollie_connect_access_token'] ?? '';
            if (empty($tenantAccessToken)) {
                throw new \RuntimeException(
                    'Tenant heeft geen Mollie Connect access token. ' .
                    'De tenant moet eerst opnieuw koppelen via Mollie Connect.'
                );
            }
            $mollieApiKey = $tenantAccessToken;
        }

        if (!$isMock && empty($mollieApiKey)) {
            throw new \RuntimeException('Geen Mollie API key beschikbaar voor payment creatie.');
        }

        // ── Proactive token refresh ────────────────────────────────────────────
        // If the token is expired or expires within 24h, refresh it BEFORE
        // creating the payment. This prevents the payment from failing with 401.
        if (!$isMock) {
            $tenantRefreshToken = $tenant['mollie_connect_refresh_token'] ?? '';
            if (!empty($tenantRefreshToken) && $this->tenantModel->isMollieTokenExpired($tenantId)) {
                try {
                    $ps = new PlatformSetting($this->db);
                    $refresher = new MollieService(
                        '', 'live',
                        $ps->get('mollie_connect_client_id'),
                        $ps->get('mollie_connect_client_secret')
                    );
                    $newTokens = $refresher->refreshAccessToken($tenantRefreshToken);

                    $this->tenantModel->updateMollieTokens(
                        $tenantId,
                        $newTokens['access_token'],
                        $newTokens['refresh_token'],
                        $newTokens['expires_at']
                    );

                    $mollieApiKey = $newTokens['access_token'];
                    error_log("Mollie token auto-refreshed for tenant {$tenantId}");
                } catch (\Throwable $e) {
                    error_log("Mollie token refresh failed for tenant {$tenantId}: " . $e->getMessage());
                    // Continue with old token — it might still work, or the
                    // payment creation will fail with a clear error
                }
            }
        }

        $mollie = new MollieService($mollieApiKey, $mollieMode);

        $payment = $mollie->createPayment(
            $amountCents,
            'Opwaarderen ' . $tenant['name'] . ' wallet',
            $redirectUrl,
            $webhookUrl,
            (string) $userId,
            null, // onBehalfOf: NOT used with tenant OAuth token (token is already scoped)
            $isMock ? 0 : $feeCents,                       // applicationFee: only for real Mollie
            $isMock ? null : ($tenant['mollie_connect_profile_id'] ?? null) // profileId: required for real Mollie
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
            'description'        => json_encode(['label' => 'Opwaardering wallet', 'tier_id' => $tierId]),
        ]);

        // Create PlatformFee record
        $platformFeeId = (new PlatformFee($this->db))->create([
            'tenant_id'           => $tenantId,
            'transaction_id'      => $transactionId,
            'mollie_payment_id'   => $payment['payment_id'],
            'user_id'             => $userId,
            'gross_amount_cents'  => $amountCents,
            'fee_percentage'      => $tenant['platform_fee_percentage'], // SNAPSHOT
            'fee_amount_cents'    => $feeCents, // Always snapshot the calculated fee; webhook may update from Mollie's authoritative value
            'net_amount_cents'    => ($amountCents - $feeCents),
            'fee_min_cents'       => $tenant['platform_fee_min_cents'],  // SNAPSHOT
            'status'              => 'collected',
        ]);

        // ═══════════════════════════════════════════════════════════════
        // MOCK MODE: Auto-process deposit immediately (no webhook needed)
        // ═══════════════════════════════════════════════════════════════
        if ($isMock) {
            // Calculate bonus for the response BEFORE processing (processDeposit is idempotent)
            $mockBonusCents = 0;
            try {
                $tierModel = new LoyaltyTier($this->db);
                $tier = null;
                $desc = json_encode(['label' => 'Opwaardering wallet', 'tier_id' => $tierId]);
                $decoded = json_decode($desc, true);
                if ($decoded && !empty($decoded['tier_id'])) {
                    $tier = $tierModel->findById((int) $decoded['tier_id'], $tenantId);
                }
                if (!$tier) {
                    $totalDeposits = $this->transactionModel->getTotalDeposits($userId, $tenantId);
                    $tier = $tierModel->determineTier($tenantId, $totalDeposits);
                }
                $modelType = $tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT;
                if ($modelType === LoyaltyTier::MODEL_BONUS) {
                    if (!empty($tier['bonus_cents']) && (int) $tier['bonus_cents'] > 0) {
                        $mockBonusCents = (int) $tier['bonus_cents'];
                    } elseif (($tier['bonus_percentage'] ?? 0) > 0) {
                        $mockBonusCents = (int) floor($amountCents * (float) $tier['bonus_percentage'] / 100);
                    }
                }
            } catch (\Throwable $e) {
                error_log('Mock bonus calculation failed: ' . $e->getMessage());
            }

            $this->processDeposit($payment['payment_id'], $amountCents);
            // Note: processDeposit() already sends notification to guest — no duplicate needed

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
                    'bonus_cents'       => $mockBonusCents,
                    'fee_cents'         => $feeCents,
                    'mollie_payment_id' => $payment['payment_id'],
                ]
            );

            return [
                'checkout_url'   => null,
                'payment_id'     => $payment['payment_id'],
                'transaction_id' => $transactionId,
                'fee_cents'      => $feeCents,
                'status'         => 'mock',
                'bonus_cents'    => $mockBonusCents,
                'total_cents'    => $amountCents + $mockBonusCents,
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
     * BONUS MODEL: If the user's active tier is a bonus model,
     * the bonus percentage is added to the credited amount.
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

        // ── BONUS CALCULATION ──────────────────────────────────────
        // Use package-specific bonus (tier_id from deposit description)
        // Falls back to determineTier() for legacy transactions without tier_id.
        $bonusCents = 0;

        try {
            $tierModel = new LoyaltyTier($this->db);
            $tier = null;
            $tierSource = 'none';

            // Try to get the specific package from the transaction description
            $desc = json_decode($transaction['description'], true);
            if ($desc && !empty($desc['tier_id'])) {
                $tier = $tierModel->findById((int) $desc['tier_id'], $tenantId);
                $tierSource = 'description';
                if (!$tier) {
                    error_log("processDeposit: tier_id={$desc['tier_id']} from description not found for tenant={$tenantId}");
                }
            }

            // Fallback: legacy transactions without tier_id in description
            if (!$tier) {
                $totalDeposits = $this->transactionModel->getTotalDeposits($userId, $tenantId);
                $tier = $tierModel->determineTier($tenantId, $totalDeposits);
                $tierSource = 'fallback_determine_tier';
                error_log("processDeposit: using fallback determineTier for payment={$molliePaymentId}, user={$userId}, totalDeposits={$totalDeposits}");
            }

            $modelType = $tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT;
            if ($modelType === LoyaltyTier::MODEL_BONUS) {
                // Use fixed bonus amount (bonus_cents) if set, otherwise fall back to percentage
                if (!empty($tier['bonus_cents']) && (int) $tier['bonus_cents'] > 0) {
                    $bonusCents = (int) $tier['bonus_cents'];
                } elseif (($tier['bonus_percentage'] ?? 0) > 0) {
                    $bonusCents = (int) floor($amountCents * (float) $tier['bonus_percentage'] / 100);
                }
                error_log("processDeposit: bonus calculated={$bonusCents}c from tier_id={$tier['id']} ({$tier['name']}), source={$tierSource}, model={$modelType}");
            } else {
                error_log("processDeposit: no bonus — tier_id={$tier['id']} ({$tier['name']}), source={$tierSource}, model={$modelType}");
            }
        } catch (\Throwable $e) {
            // Non-critical: if tier lookup fails, just credit the base amount
            error_log('Bonus calculation failed, crediting base amount: ' . $e->getMessage());
        }

        // Atomic: credit wallet balance
        try {
            $this->db->beginTransaction();

            // Lock the wallet row to prevent concurrent modifications
            $wallet = $this->walletModel->lockForUpdate($userId, $tenantId);
            if ($wallet === null) {
                throw new \RuntimeException('Wallet niet gevonden voor user ' . $userId . ' tenant ' . $tenantId);
            }

            // Credit wallet: base amount + bonus in a single atomic UPDATE
            $totalCreditCents = $amountCents + $bonusCents;
            $this->walletModel->updateBalance($userId, $totalCreditCents, 0, $tenantId);

            // Create a separate bonus transaction record for transparency
            if ($bonusCents > 0) {
                $this->transactionModel->create([
                    'tenant_id'          => $tenantId,
                    'user_id'            => $userId,
                    'bartender_id'       => null,
                    'type'               => 'bonus',
                    'final_total_cents'  => $bonusCents,
                    'ip_address'         => getClientIP(),
                    'description'        => 'Opwaardeerbonus (€' . centsToEuro($bonusCents) . ' op €' . centsToEuro($amountCents) . ')',
                ]);
            }

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
                    ['description' => 'Opwaardering wallet' . ($bonusCents > 0 ? ' (incl. €' . centsToEuro($bonusCents) . ' bonus)' : '')]
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
