<?php
declare(strict_types=1);

/**
 * Transaction Model
 * Data access layer for the transactions table (Transaction Ledger)
 */

class Transaction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find transaction by ID within a tenant
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `transactions` WHERE `id` = :id AND `tenant_id` = :tenant_id LIMIT 1'
        );
        $stmt->execute([
            ':id'        => $id,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a new transaction record
     * @return int The new transaction ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `transactions`
             (`tenant_id`, `user_id`, `bartender_id`, `type`,
              `amount_alc_cents`, `amount_food_cents`,
              `discount_alc_cents`, `discount_food_cents`,
              `final_total_cents`, `points_earned`, `points_used`,
              `ip_address`, `device_fingerprint`, `mollie_payment_id`, `description`)
             VALUES
             (:tenant_id, :user_id, :bartender_id, :type,
              :amount_alc_cents, :amount_food_cents,
              :discount_alc_cents, :discount_food_cents,
              :final_total_cents, :points_earned, :points_used,
              :ip_address, :device_fingerprint, :mollie_payment_id, :description)'
        );

        $stmt->execute([
            ':tenant_id'           => $data['tenant_id'],
            ':user_id'             => $data['user_id'],
            ':bartender_id'        => $data['bartender_id'] ?? null,
            ':type'                => $data['type'],
            ':amount_alc_cents'    => $data['amount_alc_cents'] ?? 0,
            ':amount_food_cents'   => $data['amount_food_cents'] ?? 0,
            ':discount_alc_cents'  => $data['discount_alc_cents'] ?? 0,
            ':discount_food_cents' => $data['discount_food_cents'] ?? 0,
            ':final_total_cents'   => $data['final_total_cents'],
            ':points_earned'       => $data['points_earned'] ?? 0,
            ':points_used'         => $data['points_used'] ?? 0,
            ':ip_address'          => $data['ip_address'],
            ':device_fingerprint'  => $data['device_fingerprint'] ?? null,
            ':mollie_payment_id'   => $data['mollie_payment_id'] ?? null,
            ':description'         => $data['description'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get transaction history for a user within a tenant (paginated)
     *
     * @return array{transactions: array, total: int, page: int, limit: int}
     */
    public function getByUser(int $userId, int $tenantId, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(max(1, $limit), MAX_PAGE_SIZE);
        $offset = ($page - 1) * $limit;

        // Count total
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `transactions`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $total = (int) $stmt->fetchColumn();

        // Fetch page
        $stmt = $this->db->prepare(
            'SELECT * FROM `transactions`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id
             ORDER BY `created_at` DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'transactions' => $stmt->fetchAll(),
            'total'        => $total,
            'page'         => $page,
            'limit'        => $limit,
        ];
    }

    /**
     * Get transactions by type within a tenant (for admin dashboards)
     *
     * @return array<int, array>
     */
    public function getByType(string $type, int $tenantId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `transactions`
             WHERE `type` = :type AND `tenant_id` = :tenant_id
             ORDER BY `created_at` DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Calculate total deposits for a user within a tenant
     * Used to determine the user's loyalty tier
     */
    public function getTotalDeposits(int $userId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(`final_total_cents`), 0)
             FROM `transactions`
             WHERE `user_id` = :user_id
               AND `tenant_id` = :tenant_id
               AND `type` = :type'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
            ':type'      => 'deposit',
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find transaction by Mollie payment ID
     */
    public function findByMolliePaymentId(string $molliePaymentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `transactions` WHERE `mollie_payment_id` = :mollie_id LIMIT 1'
        );
        $stmt->execute([':mollie_id' => $molliePaymentId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get revenue stats for a tenant (today, this week)
     *
     * @return array{today: int, week: int, total: int}
     */
    public function getRevenueStats(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(`created_at`) = CURDATE() THEN `final_total_cents` ELSE 0 END), 0) AS today,
                COALESCE(SUM(CASE WHEN `created_at` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN `final_total_cents` ELSE 0 END), 0) AS week,
                COALESCE(SUM(`final_total_cents`), 0) AS total
             FROM `transactions`
             WHERE `tenant_id` = :tenant_id AND `type` = 'payment'"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return [
            'today' => (int) ($row['today'] ?? 0),
            'week'  => (int) ($row['week'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }
}
