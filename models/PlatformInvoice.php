<?php
declare(strict_types=1);

/**
 * PlatformInvoice Model
 * Data access layer for the platform_invoices table
 *
 * Verzamelfacturen — maandelijks/wekelijks per tenant gegenereerd.
 * - BTW: 21% over de platform fee (dienstverlening)
 * - Vermelding: "Verrekend via Mollie bij betaling"
 * - Status flow: draft → sent → paid (of overdue → paid)
 */

class PlatformInvoice
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate the next invoice number
     * Format: PI-YYYY-MM-NNN (bijv. PI-2026-04-001)
     */
    public function generateInvoiceNumber(): string
    {
        $prefix = 'PI-' . date('Y-m') . '-';

        $stmt = $this->db->prepare(
            "SELECT MAX(`invoice_number`) AS `last_number`
             FROM `platform_invoices`
             WHERE `invoice_number` LIKE :prefix"
        );
        $stmt->execute([':prefix' => $prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        if ($lastNumber) {
            // Extract the sequence number
            $parts = explode('-', $lastNumber);
            $seq = (int) end($parts) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create a new invoice (draft)
     *
     * @param array{
     *     tenant_id: int,
     *     invoice_number: string,
     *     period_start: string,
     *     period_end: string,
     *     period_type: string,
     *     transaction_count: int,
     *     gross_total_cents: int,
     *     fee_total_cents: int,
     *     btw_percentage: float,
     *     btw_amount_cents: int,
     *     total_incl_btw_cents: int
     * } $data
     * @return int The new invoice ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `platform_invoices`
             (`tenant_id`, `invoice_number`, `period_start`, `period_end`, `period_type`,
              `transaction_count`, `gross_total_cents`, `fee_total_cents`,
              `btw_percentage`, `btw_amount_cents`, `total_incl_btw_cents`, `status`)
             VALUES
             (:tenant_id, :invoice_number, :period_start, :period_end, :period_type,
              :transaction_count, :gross_total_cents, :fee_total_cents,
              :btw_percentage, :btw_amount_cents, :total_incl_btw_cents, :status)'
        );

        $stmt->execute([
            ':tenant_id'            => $data['tenant_id'],
            ':invoice_number'       => $data['invoice_number'],
            ':period_start'         => $data['period_start'],
            ':period_end'           => $data['period_end'],
            ':period_type'          => $data['period_type'],
            ':transaction_count'    => $data['transaction_count'],
            ':gross_total_cents'    => $data['gross_total_cents'],
            ':fee_total_cents'      => $data['fee_total_cents'],
            ':btw_percentage'       => $data['btw_percentage'],
            ':btw_amount_cents'     => $data['btw_amount_cents'],
            ':total_incl_btw_cents' => $data['total_incl_btw_cents'],
            ':status'               => $data['status'] ?? 'draft',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find invoice by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pi.*, t.`name` AS tenant_name, t.`btw_number`, t.`contact_name`,
                    t.`contact_email`, t.`address`, t.`postal_code`, t.`city`
             FROM `platform_invoices` pi
             JOIN `tenants` t ON t.`id` = pi.`tenant_id`
             WHERE pi.`id` = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find invoice by invoice number
     */
    public function findByNumber(string $invoiceNumber): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_invoices` WHERE `invoice_number` = :number LIMIT 1'
        );
        $stmt->execute([':number' => $invoiceNumber]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all invoices (super-admin, paginated)
     *
     * @return array{invoices: array, total: int, page: int, limit: int}
     */
    public function getAll(int $page = 1, int $limit = 50, string $status = null): array
    {
        $page = max(1, $page);
        $limit = min(max(1, $limit), MAX_PAGE_SIZE);
        $offset = ($page - 1) * $limit;

        $where = '1=1';
        $params = [];

        if ($status !== null && in_array($status, ['draft', 'sent', 'paid', 'overdue', 'cancelled'], true)) {
            $where .= ' AND `status` = :status';
            $params[':status'] = $status;
        }

        $countSql = "SELECT COUNT(*) FROM `platform_invoices` WHERE {$where}";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $fetchSql = "
            SELECT pi.*, t.`name` AS tenant_name
            FROM `platform_invoices` pi
            JOIN `tenants` t ON t.`id` = pi.`tenant_id`
            WHERE {$where}
            ORDER BY pi.`created_at` DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($fetchSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'invoices' => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
        ];
    }

    /**
     * Get invoices for a specific tenant
     *
     * @return array<int, array>
     */
    public function getByTenant(int $tenantId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_invoices`
             WHERE `tenant_id` = :tenant_id
             ORDER BY `created_at` DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get fees linked to an invoice
     *
     * @return array<int, array>
     */
    public function getFees(int $invoiceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pf.* FROM `platform_fees` pf
             WHERE pf.`invoice_id` = :invoice_id
             ORDER BY pf.`created_at` ASC'
        );
        $stmt->execute([':invoice_id' => $invoiceId]);
        return $stmt->fetchAll();
    }

    /**
     * Update invoice status
     */
    public function updateStatus(int $invoiceId, string $newStatus): bool
    {
        $allowedStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException("Invalid invoice status: {$newStatus}");
        }

        $extraSet = '';
        $params = [':status' => $newStatus, ':id' => $invoiceId];

        if ($newStatus === 'sent') {
            $extraSet = ', `sent_at` = NOW()';
        } elseif ($newStatus === 'paid') {
            $extraSet = ', `paid_at` = NOW()';
        } elseif ($newStatus === 'cancelled') {
            $extraSet = ', `cancelled_at` = NOW()';
        }

        $stmt = $this->db->prepare(
            "UPDATE `platform_invoices` SET `status` = :status{$extraSet} WHERE `id` = :id"
        );
        return $stmt->execute($params);
    }

    /**
     * Check if an invoice already exists for a tenant+period
     */
    public function existsForPeriod(int $tenantId, string $periodStart, string $periodEnd): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `platform_invoices`
             WHERE `tenant_id` = :tenant_id
               AND `period_start` = :start
               AND `period_end` = :end
               AND `status` != :cancelled
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':start'     => $periodStart,
            ':end'       => $periodEnd,
            ':cancelled' => 'cancelled',
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get invoice totals for super-admin dashboard
     *
     * @return array{total_outstanding: int, total_collected: int, this_month_invoiced: int}
     */
    public function getTotals(): array
    {
        $stmt = $this->db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN `status` IN ('draft','sent','overdue') THEN `total_incl_btw_cents` ELSE 0 END), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN `status` = 'paid' THEN `total_incl_btw_cents` ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN `status` != 'cancelled' AND `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN `total_incl_btw_cents` ELSE 0 END), 0) AS this_month
             FROM `platform_invoices`"
        );
        $row = $stmt->fetch();

        return [
            'total_outstanding'    => (int) ($row['outstanding'] ?? 0),
            'total_collected'      => (int) ($row['collected'] ?? 0),
            'this_month_invoiced'  => (int) ($row['this_month'] ?? 0),
        ];
    }
}
