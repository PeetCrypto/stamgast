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
     * Update wallet balance (atomic)
     */
    public function updateBalance(int $userId, int $balanceDelta, int $pointsDelta = 0): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `wallets` SET `balance_cents` = `balance_cents` + :balance_delta, `points_cents` = `points_cents` + :points_delta WHERE `user_id` = :user_id'
        );
        return $stmt->execute([
            ':balance_delta' => $balanceDelta,
            ':points_delta'  => $pointsDelta,
            ':user_id'       => $userId,
        ]);
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
