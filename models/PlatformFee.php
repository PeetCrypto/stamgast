<?php
declare(strict_types=1);

/**
 * PlatformFee Model
 * Data access layer for the platform_fees table
 *
 * Fee Ledger — per-transactie audit trail voor platform fees.
 * - fee_percentage is GESNAPSHOT op moment van transactie
 * - fee_amount komt uit Mollie webhook (applicationFee.amount), NIET herberekend
 * - status flow: collected → invoiced → settled
 */

class PlatformFee
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new platform fee record
     *
     * @param array{
     *     tenant_id: int,
     *     transaction_id: int,
     *     mollie_payment_id: string|null,
     *     user_id: int,
     *     gross_amount_cents: int,
     *     fee_percentage: float,
     *     fee_amount_cents: int,
     *     net_amount_cents: int,
     *     fee_min_cents: int
     * } $data
     * @return int The new platform_fee ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `platform_fees`
             (`tenant_id`, `transaction_id`, `mollie_payment_id`, `user_id`,
              `gross_amount_cents`, `fee_percentage`, `fee_amount_cents`,
              `net_amount_cents`, `fee_min_cents`, `status`)
             VALUES
             (:tenant_id, :transaction_id, :mollie_payment_id, :user_id,
              :gross_amount_cents, :fee_percentage, :fee_amount_cents,
              :net_amount_cents, :fee_min_cents, :status)'
        );

        $stmt->execute([
            ':tenant_id'          => $data['tenant_id'],
            ':transaction_id'     => $data['transaction_id'],
            ':mollie_payment_id'  => $data['mollie_payment_id'] ?? null,
            ':user_id'            => $data['user_id'],
            ':gross_amount_cents' => $data['gross_amount_cents'],
            ':fee_percentage'     => $data['fee_percentage'],
            ':fee_amount_cents'   => $data['fee_amount_cents'],
            ':net_amount_cents'   => $data['net_amount_cents'],
            ':fee_min_cents'      => $data['fee_min_cents'] ?? 0,
            ':status'             => $data['status'] ?? 'collected',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find fee by transaction ID (1:1 relatie)
     */
    public function findByTransactionId(int $transactionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_fees` WHERE `transaction_id` = :transaction_id LIMIT 1'
        );
        $stmt->execute([':transaction_id' => $transactionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find fee by Mollie payment ID
     */
    public function findByMolliePaymentId(string $molliePaymentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_fees` WHERE `mollie_payment_id` = :mollie_id LIMIT 1'
        );
        $stmt->execute([':mollie_id' => $molliePaymentId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Update fee amount from Mollie webhook (applicationFee truth)
     * ⚠️ NEVER recalculate — use Mollie's authoritative value
     */
    public function updateFeeFromMollie(int $feeId, int $feeAmountCents, int $mollieFeeCents = 0): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `platform_fees`
             SET `fee_amount_cents` = :fee_amount,
                 `net_amount_cents` = `gross_amount_cents` - :fee_amount,
                 `mollie_fee_cents` = :mollie_fee
             WHERE `id` = :id'
        );
        return $stmt->execute([
            ':fee_amount' => $feeAmountCents,
            ':mollie_fee' => $mollieFeeCents,
            ':id'         => $feeId,
        ]);
    }

    /**
     * Update fee status
     */
    public function updateStatus(int $feeId, string $newStatus): bool
    {
        $allowedStatuses = ['collected', 'invoiced', 'settled'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException("Invalid fee status: {$newStatus}");
        }

        $stmt = $this->db->prepare(
            'UPDATE `platform_fees` SET `status` = :status WHERE `id` = :id'
        );
        return $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $feeId,
        ]);
    }

    /**
     * Link fees to an invoice (batch update)
     *
     * @param array<int> $feeIds
     */
    public function linkToInvoice(array $feeIds, int $invoiceId): int
    {
        if (empty($feeIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($feeIds), '?'));
        $params = [...$feeIds, $invoiceId, 'invoiced'];

        $stmt = $this->db->prepare(
            "UPDATE `platform_fees`
             SET `invoice_id` = ?, `status` = ?
             WHERE `id` IN ({$placeholders}) AND `status` = 'collected'"
        );

        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get fees by tenant (paginated, filterable by status)
     *
     * @return array{fees: array, total: int, page: int, limit: int}
     */
    public function getByTenant(
        int $tenantId,
        string $status = null,
        int $page = 1,
        int $limit = 50
    ): array {
        $page = max(1, $page);
        $limit = min(max(1, $limit), MAX_PAGE_SIZE);
        $offset = ($page - 1) * $limit;

        $where = 'pf.`tenant_id` = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($status !== null && in_array($status, ['collected', 'invoiced', 'settled'], true)) {
            $where .= ' AND pf.`status` = :status';
            $params[':status'] = $status;
        }

        // Count
        $countSql = "SELECT COUNT(*) FROM `platform_fees` pf WHERE {$where}";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch
        $fetchSql = "SELECT pf.*, u.`first_name`, u.`last_name`, u.`email`
                     FROM `platform_fees` pf
                     LEFT JOIN `users` u ON u.`id` = pf.`user_id`
                     WHERE {$where}
                     ORDER BY pf.`created_at` DESC
                     LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($fetchSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'fees'  => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Get collected fees for a tenant within a date range (for invoicing)
     *
     * @return array<int, array>
     */
    public function getCollectedForPeriod(int $tenantId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_fees`
             WHERE `tenant_id` = :tenant_id
               AND `status` = :status
               AND `created_at` >= :start
               AND `created_at` < :end + INTERVAL 1 DAY
             ORDER BY `created_at` ASC'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':status'    => 'collected',
            ':start'     => $periodStart,
            ':end'       => $periodEnd,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get fee summary for a tenant in a period
     *
     * @return array{count: int, gross_total: int, fee_total: int, net_total: int}
     */
    public function getSummaryForPeriod(int $tenantId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS `count`,
                    COALESCE(SUM(`gross_amount_cents`), 0) AS `gross_total`,
                    COALESCE(SUM(`fee_amount_cents`), 0) AS `fee_total`,
                    COALESCE(SUM(`net_amount_cents`), 0) AS `net_total`
             FROM `platform_fees`
             WHERE `tenant_id` = :tenant_id
               AND `status` IN (:status_collected, :status_invoiced, :status_settled)
               AND `created_at` >= :start
               AND `created_at` < :end + INTERVAL 1 DAY'
        );
        $stmt->execute([
            ':tenant_id'        => $tenantId,
            ':status_collected' => 'collected',
            ':status_invoiced'  => 'invoiced',
            ':status_settled'   => 'settled',
            ':start'            => $periodStart,
            ':end'              => $periodEnd,
        ]);
        $row = $stmt->fetch();

        return [
            'count'      => (int) ($row['count'] ?? 0),
            'gross_total' => (int) ($row['gross_total'] ?? 0),
            'fee_total'  => (int) ($row['fee_total'] ?? 0),
            'net_total'  => (int) ($row['net_total'] ?? 0),
        ];
    }

    /**
     * Get platform-wide fee totals (super-admin overview)
     *
     * @return array{today: array, this_month: array, all_time: array}
     */
    public function getPlatformOverview(): array
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN DATE(`created_at`) = CURDATE() THEN `fee_amount_cents` ELSE 0 END), 0) AS today_fee,
                COALESCE(SUM(CASE WHEN DATE(`created_at`) = CURDATE() THEN `gross_amount_cents` ELSE 0 END), 0) AS today_gross,
                COALESCE(SUM(CASE WHEN `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN `fee_amount_cents` ELSE 0 END), 0) AS month_fee,
                COALESCE(SUM(CASE WHEN `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN `gross_amount_cents` ELSE 0 END), 0) AS month_gross,
                COALESCE(SUM(`fee_amount_cents`), 0) AS all_fee,
                COALESCE(SUM(`gross_amount_cents`), 0) AS all_gross
            FROM `platform_fees`
        ";

        $stmt = $this->db->query($sql);
        $row = $stmt->fetch();

        return [
            'today' => [
                'fee_total'  => (int) ($row['today_fee'] ?? 0),
                'gross_total' => (int) ($row['today_gross'] ?? 0),
            ],
            'this_month' => [
                'fee_total'  => (int) ($row['month_fee'] ?? 0),
                'gross_total' => (int) ($row['month_gross'] ?? 0),
            ],
            'all_time' => [
                'fee_total'  => (int) ($row['all_fee'] ?? 0),
                'gross_total' => (int) ($row['all_gross'] ?? 0),
            ],
        ];
    }

    /**
     * Get per-tenant fee totals (super-admin overview, sorted by fee desc)
     *
     * @return array<int, array{tenant_id: int, tenant_name: string, fee_total: int, transaction_count: int}>
     */
    public function getPerTenantOverview(string $periodStart = null, string $periodEnd = null): array
    {
        $where = '';
        $params = [];

        if ($periodStart !== null && $periodEnd !== null) {
            $where = ' WHERE pf.`created_at` >= :start AND pf.`created_at` < :end + INTERVAL 1 DAY ';
            $params[':start'] = $periodStart;
            $params[':end'] = $periodEnd;
        }

        $sql = "
            SELECT t.`id` AS tenant_id, t.`name` AS tenant_name,
                   COALESCE(SUM(pf.`fee_amount_cents`), 0) AS fee_total,
                   COALESCE(SUM(pf.`gross_amount_cents`), 0) AS gross_total,
                   COUNT(pf.`id`) AS transaction_count
            FROM `tenants` t
            LEFT JOIN `platform_fees` pf ON pf.`tenant_id` = t.`id`
            {$where}
            GROUP BY t.`id`, t.`name`
            ORDER BY fee_total DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
