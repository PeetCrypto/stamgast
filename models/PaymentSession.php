<?php
declare(strict_types=1);

/**
 * PaymentSession Model
 * Data access layer for the pos_payment_sessions table
 *
 * Status machine: pending -> scanned -> confirmed/cancelled/expired/failed
 */

class PaymentSession
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new payment session
     *
     * @return array The created session row
     */
    public function create(int $tenantId, int $bartenderId, int $amountAlcCents, int $amountFoodCents): array
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex token
        $expiresAt = date('Y-m-d H:i:s', time() + POS_SESSION_EXPIRY_SECONDS);

        $stmt = $this->db->prepare(
            'INSERT INTO `pos_payment_sessions`
                (`session_token`, `tenant_id`, `bartender_id`, `amount_alc_cents`, `amount_food_cents`, `status`, `expires_at`)
             VALUES (:token, :tenant_id, :bartender_id, :alc, :food, \'pending\', :expires)'
        );
        $stmt->execute([
            ':token'        => $token,
            ':tenant_id'    => $tenantId,
            ':bartender_id' => $bartenderId,
            ':alc'          => $amountAlcCents,
            ':food'         => $amountFoodCents,
            ':expires'      => $expiresAt,
        ]);

        return $this->findByToken($token);
    }

    /**
     * Find a session by its token
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `pos_payment_sessions` WHERE `session_token` = :token LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a session by token AND verify it belongs to the given tenant
     */
    public function findByTokenAndTenant(string $token, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `pos_payment_sessions`
             WHERE `session_token` = :token AND `tenant_id` = :tenant_id LIMIT 1'
        );
        $stmt->execute([':token' => $token, ':tenant_id' => $tenantId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a session by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `pos_payment_sessions` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Mark session as scanned by a guest — links the guest to the session
     * Also calculates discounts and final total server-side
     */
    public function markScanned(int $id, int $guestUserId, string $guestName, int $discountAlcCents, int $discountFoodCents, int $finalTotalCents): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `pos_payment_sessions`
             SET `guest_user_id` = :guest_id,
                 `guest_name` = :guest_name,
                 `discount_alc_cents` = :disc_alc,
                 `discount_food_cents` = :disc_food,
                 `final_total_cents` = :final_total,
                 `status` = \'scanned\',
                 `scanned_at` = NOW()
             WHERE `id` = :id AND `status` = \'pending\''
        );
        $stmt->execute([
            ':guest_id'   => $guestUserId,
            ':guest_name' => $guestName,
            ':disc_alc'   => $discountAlcCents,
            ':disc_food'  => $discountFoodCents,
            ':final_total' => $finalTotalCents,
            ':id'         => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Mark session as confirmed (payment successful)
     */
    public function markConfirmed(int $id, int $transactionId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `pos_payment_sessions`
             SET `status` = \'confirmed\',
                 `transaction_id` = :tx_id,
                 `confirmed_at` = NOW()
             WHERE `id` = :id AND `status` = \'scanned\''
        );
        $stmt->execute([
            ':tx_id' => $transactionId,
            ':id'    => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Mark session as cancelled by guest
     */
    public function markCancelled(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `pos_payment_sessions`
             SET `status` = \'cancelled\',
                 `cancelled_at` = NOW()
             WHERE `id` = :id AND `status` IN (\'pending\', \'scanned\')'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Mark session as failed
     */
    public function markFailed(int $id, string $errorMessage): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `pos_payment_sessions`
             SET `status` = \'failed\',
                 `error_message` = :error
             WHERE `id` = :id AND `status` IN (\'scanned\', \'pending\')'
        );
        $stmt->execute([
            ':error' => $errorMessage,
            ':id'    => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Expire old pending sessions (cleanup)
     */
    public function expireOldSessions(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE `pos_payment_sessions`
             SET `status` = \'expired\'
             WHERE `tenant_id` = :tenant_id
               AND `status` = \'pending\'
               AND `expires_at` < NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Check if session is still valid (not expired, correct status)
     */
    public function isValid(array $session): bool
    {
        if (!$session) return false;
        $status = $session['status'] ?? '';
        if (!in_array($status, ['pending', 'scanned'], true)) return false;
        $expiresAt = strtotime($session['expires_at']);
        return $expiresAt > time();
    }
}
