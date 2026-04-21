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

        // ── STAP 2: USER & TIER OPHALEN ─────────────────────────────
        $userModel = new User($this->db);
        $user = $userModel->findById($userId);
        if ($user === null || (int) $user['tenant_id'] !== $tenantId) {
            throw new \InvalidArgumentException('Gebruiker niet gevonden binnen deze tenant');
        }

        // Calculate total deposits to determine tier
        $transactionModel = new Transaction($this->db);
        $totalDeposits = $transactionModel->getTotalDeposits($userId, $tenantId);

        $tierModel = new LoyaltyTier($this->db);
        $tier = $tierModel->determineTier($tenantId, $totalDeposits);

        // ── STAP 3: KORTING BEREKENEN (SERVER-SIDE!) ────────────────
        $alcDiscountPerc = min((float) $tier['alcohol_discount_perc'], (float) ALCOHOL_DISCOUNT_MAX);
        $foodDiscountPerc = min((float) $tier['food_discount_perc'], (float) FOOD_DISCOUNT_MAX);

        $discountAlcCents = (int) floor($amountAlcCents * $alcDiscountPerc / 100);
        $discountFoodCents = (int) floor($amountFoodCents * $foodDiscountPerc / 100);

        $finalTotal = ($amountAlcCents - $discountAlcCents) + ($amountFoodCents - $discountFoodCents);
        $discountTotal = $discountAlcCents + $discountFoodCents;

        // Calculate points earned: FLOOR(final_total * multiplier / 100)
        $pointsMultiplier = (float) $tier['points_multiplier'];
        $pointsEarned = (int) floor($finalTotal * $pointsMultiplier / 100);

        // ── STAP 4: SALDO CHECK (DOUBLE-SPEND PROTECTIE) ────────────
        $walletModel = new Wallet($this->db);
        $wallet = $walletModel->findByUserAndTenant($userId, $tenantId);

        if ($wallet === null) {
            throw new \RuntimeException('Wallet niet gevonden');
        }

        $currentBalance = (int) $wallet['balance_cents'];
        if ($currentBalance < $finalTotal) {
            $shortage = $finalTotal - $currentBalance;
            throw new \RuntimeException(
                'Onvoldoende saldo. Tekort: €' . centsToEuro($shortage)
            );
        }

        // ── STAP 5: ATOMAIRE TRANSACTIE ─────────────────────────────
        try {
            $this->db->beginTransaction();

            // a) Deduct from wallet
            $walletModel->updateBalance($userId, -$finalTotal, $pointsEarned);

            // b) Create transaction record
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

            // c) Audit trail
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

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Betaling mislukt: ' . $e->getMessage());
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
