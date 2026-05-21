<?php
declare(strict_types=1);

/**
 * Payment Service
 * Kassa & kortingslogica — the "Hufterproof" payment engine
 *
 * Volledige 6-staps betalingsflow:
 * 1. Input validatie
 * 2. User & tier ophalen
 * 3. Kortingen berekenen (SERVER-SIDE, alcohol capped op 25%)
 * 4. Sald check (double-spend protectie)
 * 5. Atomaire transactie (PDO beginTransaction -> commit)
 * 6. Response + audit
 */

require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/LoyaltyTier.php';
require_once __DIR__ . '/../models/User.php';

class PaymentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Process a payment from bartender POS
     *
     * @param int $userId        Guest user ID (from QR scan)
     * @param int $tenantId      Tenant ID
     * @param int $bartenderId   Bartender processing the payment
     * @param int $amountAlcCents  Alcohol total in cents
     * @param int $amountFoodCents Food total in cents
     * @return array{success: true, transaction_id: int, final_total: int, discount_applied: int, points_earned: int}
     * @throws \InvalidArgumentException on validation errors
     * @throws \RuntimeException on insufficient balance or DB errors
     */
    public function processPayment(
        int $userId,
        int $tenantId,
        int $bartenderId,
        int $amountAlcCents,
        int $amountFoodCents
    ): array {
        // ── STAP 1: INPUT VALIDATIE ──────────────────────────────────
        if ($amountAlcCents < 0 || $amountFoodCents < 0) {
            throw new \InvalidArgumentException('Bedragen mogen niet negatief zijn');
        }
        if ($amountAlcCents === 0 && $amountFoodCents === 0) {
            throw new \InvalidArgumentException('Er moet minimaal één bedrag ingevuld zijn');
        }

        // ── STAP 1b: IDEMPOTENTIE CHECK (dubbele betaling voorkomen) ─
        // Als een identieke betaling binnen 60 seconden al is verwerkt,
        // retourneer het bestaande resultaat i.p.v. dubbel af te schrijven.
        $transactionModel = new Transaction($this->db);
        $duplicate = $transactionModel->findRecentDuplicatePayment(
            $userId,
            $tenantId,
            $bartenderId,
            $amountAlcCents,
            $amountFoodCents,
            60 // 60 seconden dedup window
        );

        if ($duplicate !== null) {
            // Log de duplicate poging
            $audit = new Audit($this->db);
            $audit->log(
                $tenantId,
                $bartenderId,
                'payment.duplicate_blocked',
                'transaction',
                (int) $duplicate['id'],
                [
                    'user_id'           => $userId,
                    'original_tx_id'    => (int) $duplicate['id'],
                    'amount_alc_cents'  => $amountAlcCents,
                    'amount_food_cents' => $amountFoodCents,
                ]
            );

            // Retourneer het originele transactie-resultaat
            return [
                'success'           => true,
                'transaction_id'    => (int) $duplicate['id'],
                'final_total'       => (int) $duplicate['final_total_cents'],
                'discount_applied'  => (int) $duplicate['discount_alc_cents'] + (int) $duplicate['discount_food_cents'],
                'points_earned'     => (int) $duplicate['points_earned'],
                'duplicate'         => true,
            ];
        }

        // ── STAP 2: USER & TIER OPHALEN ─────────────────────────────
        $userModel = new User($this->db);
        $user = $userModel->findById($userId);
        if ($user === null || (int) $user['tenant_id'] !== $tenantId) {
            throw new \InvalidArgumentException('Gebruiker niet gevonden binnen deze tenant');
        }

        // Gated onboarding: check account status
        if (($user['account_status'] ?? '') === 'suspended') {
            throw new \RuntimeException('Dit account is geblokkeerd door de beheerder.');
        }
        if (($user['account_status'] ?? '') !== 'active') {
            throw new \RuntimeException('Gast is nog niet geverifieerd. Vraag om legitimatie aan de bar.');
        }

        // Calculate total deposits to determine tier
        $transactionModel = new Transaction($this->db);
        $totalDeposits = $transactionModel->getTotalDeposits($userId, $tenantId);

        $tierModel = new LoyaltyTier($this->db);
        $tier = $tierModel->determineTier($tenantId, $totalDeposits);

        // ── STAP 3: KORTING BEREKENEN (SERVER-SIDE!) ────────────────
        // BONUS MODEL: alcohol discount is always 0, only food discount (if set) applies.
        // DISCOUNT MODEL: both alcohol and food discounts apply (standard behavior).
        $isBonusModel = ($tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT) === LoyaltyTier::MODEL_BONUS;

        $alcDiscountPerc = $isBonusModel
            ? 0 // No alcohol discount in bonus model
            : min((float) $tier['alcohol_discount_perc'], (float) ALCOHOL_DISCOUNT_MAX);
        $foodDiscountPerc = min((float) $tier['food_discount_perc'], (float) FOOD_DISCOUNT_MAX);

        $discountAlcCents = (int) floor($amountAlcCents * $alcDiscountPerc / 100);
        $discountFoodCents = (int) floor($amountFoodCents * $foodDiscountPerc / 100);

        $finalTotal = ($amountAlcCents - $discountAlcCents) + ($amountFoodCents - $discountFoodCents);
        $discountTotal = $discountAlcCents + $discountFoodCents;

        // Calculate points earned: FLOOR(final_total * multiplier / 100)
        // Skip points if tenant has disabled the points system
        $tenantModelPts = new Tenant($this->db);
        $tenantRow = $tenantModelPts->findById($tenantId);
        $pointsEnabled = (bool) ($tenantRow['points_enabled'] ?? true);

        $pointsMultiplier = (float) $tier['points_multiplier'];
        $pointsEarned = $pointsEnabled ? (int) floor($finalTotal * $pointsMultiplier / 100) : 0;

        // ── STAP 4+5: ATOMAIRE TRANSACTIE (saldo check + afschrijving in 1 lock) ──
        // De saldo-check en afschrijving zijn IN de transactie met row-lock
        // om race conditions te voorkomen (twee bartenders tegelijk).
        $walletModel = new Wallet($this->db);

        try {
            $this->db->beginTransaction();

            // a) Lock wallet row (SELECT ... FOR UPDATE voorkomt concurrent reads)
            $wallet = $walletModel->lockForUpdate($userId, $tenantId);

            if ($wallet === null) {
                $this->db->rollBack();
                throw new \RuntimeException('Wallet niet gevonden');
            }

            $currentBalance = (int) $wallet['balance_cents'];
            if ($currentBalance < $finalTotal) {
                $this->db->rollBack();
                $shortage = $finalTotal - $currentBalance;
                throw new \RuntimeException(
                    'Onvoldoende saldo. Tekort: €' . centsToEuro($shortage)
                );
            }

            // b) Deduct from wallet (SQL-level guard against negative balance)
            $deducted = $walletModel->updateBalance($userId, -$finalTotal, $pointsEarned);
            if (!$deducted) {
                $this->db->rollBack();
                throw new \RuntimeException('Onvoldoende saldo. Probeer opnieuw.');
            }

            // c) Create transaction record
            $transactionId = $transactionModel->create([
                'tenant_id'           => $tenantId,
                'user_id'             => $userId,
                'bartender_id'        => $bartenderId,
                'type'                => 'payment',
                'amount_alc_cents'    => $amountAlcCents,
                'amount_food_cents'   => $amountFoodCents,
                'discount_alc_cents'  => $discountAlcCents,
                'discount_food_cents' => $discountFoodCents,
                'final_total_cents'   => $finalTotal,
                'points_earned'       => $pointsEarned,
                'ip_address'          => getClientIP(),
                'description'         => 'Betaling via POS',
            ]);

            // d) Audit trail
            $audit = new Audit($this->db);
            $audit->log(
                $tenantId,
                $bartenderId,
                'payment.processed',
                'transaction',
                $transactionId,
                [
                    'user_id'           => $userId,
                    'final_total_cents' => $finalTotal,
                    'discount_cents'    => $discountTotal,
                    'points_earned'     => $pointsEarned,
                    'tier_name'         => $tier['name'],
                ]
            );

            $this->db->commit();

        } catch (\InvalidArgumentException $e) {
            // Re-throw validation errors without wrapping
            throw $e;
        } catch (\RuntimeException $e) {
            // Re-throw runtime errors (insufficient balance) without wrapping
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Betaling mislukt: ' . $e->getMessage());
        }

        // Send notification + email to guest (non-critical)
        try {
            require_once __DIR__ . '/NotificationService.php';
            $notifService = new NotificationService($this->db);
            $notifService->notifyTransaction(
                $userId, $tenantId, $transactionId, 'payment', $finalTotal,
                ['discount_alc_cents' => $discountAlcCents, 'discount_food_cents' => $discountFoodCents, 'points_earned' => $pointsEarned, 'description' => 'Betaling via POS']
            );
        } catch (\Throwable $e) {
            error_log('Notification after payment failed: ' . $e->getMessage());
        }

        // ── STAP 6: RESPONSE ────────────────────────────────────────
        return [
            'success'           => true,
            'transaction_id'    => $transactionId,
            'final_total'       => $finalTotal,
            'discount_applied'  => $discountTotal,
            'points_earned'     => $pointsEarned,
        ];
    }
}
