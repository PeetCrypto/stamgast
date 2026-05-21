<?php
declare(strict_types=1);

/**
 * Wallet Model
 * Data access layer for the wallets table
 */

class Wallet
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find wallet by user ID
     */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM `wallets` WHERE `user_id` = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find wallet by user ID within a tenant
     */
    public function findByUserAndTenant(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `wallets` WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id LIMIT 1'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Update wallet balance (atomic, race-condition safe)
     *
     * For deductions (negative delta): SQL WHERE clause prevents negative balance.
     * Returns true if updated, false if insufficient balance (race condition blocked).
     *
     * @param int $userId
     * @param int $balanceDelta  Positive = credit, negative = debit
     * @param int $pointsDelta
     * @return bool True if row was updated, false if blocked (insufficient balance)
     */
    public function updateBalance(int $userId, int $balanceDelta, int $pointsDelta = 0): bool
    {
        // For credits (deposits), no balance guard needed
        if ($balanceDelta >= 0) {
            $stmt = $this->db->prepare(
                'UPDATE `wallets` SET `balance_cents` = `balance_cents` + :balance_delta, `points_cents` = `points_cents` + :points_delta WHERE `user_id` = :user_id'
            );
            $stmt->execute([
                ':balance_delta' => $balanceDelta,
                ':points_delta'  => $pointsDelta,
                ':user_id'       => $userId,
            ]);
            return $stmt->rowCount() === 1;
        }

        // For debits (payments): prevent negative balance at SQL level
        // abs() because delta is negative — we need the minimum required balance
        $minRequired = abs($balanceDelta);
        $stmt = $this->db->prepare(
            'UPDATE `wallets` SET `balance_cents` = `balance_cents` + :balance_delta, `points_cents` = `points_cents` + :points_delta WHERE `user_id` = :user_id AND `balance_cents` >= :min_required'
        );
        $stmt->execute([
            ':balance_delta' => $balanceDelta,
            ':points_delta'  => $pointsDelta,
            ':user_id'       => $userId,
            ':min_required'  => $minRequired,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Lock wallet row for atomic read-then-write operations (SELECT ... FOR UPDATE)
     * MUST be called within an active PDO transaction to hold the lock.
     * Prevents race conditions where two concurrent requests read the same balance.
     *
     * @param int $userId
     * @param int $tenantId
     * @return array|null Wallet row or null if not found
     */
    public function lockForUpdate(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `wallets` WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a wallet for a user
     */
    public function create(int $userId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `wallets` (`user_id`, `tenant_id`, `balance_cents`, `points_cents`) VALUES (:user_id, :tenant_id, 0, 0)'
        );
        return $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
    }
}
