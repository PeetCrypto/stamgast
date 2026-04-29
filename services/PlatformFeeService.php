<?php
declare(strict_types=1);

/**
 * Platform Fee Service
 * Business logic for platform fee calculation and invoice generation
 *
 * Responsibilities:
 * - Calculate fee amount based on tenant configuration
 * - Generate monthly/weekly collection invoices
 * - Batch invoice generation for all tenants
 * - Fee statistics for super-admin dashboard
 */

require_once __DIR__ . '/../models/PlatformFee.php';
require_once __DIR__ . '/../models/PlatformInvoice.php';
require_once __DIR__ . '/../models/Tenant.php';

class PlatformFeeService
{
    private PDO $db;
    private PlatformFee $feeModel;
    private PlatformInvoice $invoiceModel;
    private Tenant $tenantModel;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->feeModel = new PlatformFee($db);
        $this->invoiceModel = new PlatformInvoice($db);
        $this->tenantModel = new Tenant($db);
    }

    /**
     * Calculate platform fee for a given amount
     *
     * @param int   $amountCents  Gross amount
     * @param float $percentage   Fee percentage (e.g. 1.00 = 1%)
     * @param int   $minCents     Minimum fee in cents
     * @return int  Fee in cents (rounded down, respecting minimum)
     */
    public function calculateFee(int $amountCents, float $percentage, int $minCents): int
    {
        $calculated = (int) floor($amountCents * $percentage / 100);
        return max($calculated, $minCents);
    }

    /**
     * Generate a single invoice for a tenant (periodic collection)
     *
     * @throws \RuntimeException if invoice already exists or no collected fees
     * @return array{invoice_id: int, invoice_number: string, totals: array}
     */
    public function generateMonthlyInvoice(
        int $tenantId,
        string $periodStart,
        string $periodEnd,
        ?string $periodType = null
    ): array {
        $this->db->beginTransaction();
        try {
            // Check for duplicate
            $existing = $this->invoiceModel->existsForPeriod($tenantId, $periodStart, $periodEnd);
            if ($existing) {
                throw new \RuntimeException('Invoice already exists for this period (Invoice #' . $existing['invoice_number'] . ')');
            }

            // Get tenant config (for period type if not provided)
            $tenant = $this->tenantModel->findById($tenantId);
            if (!$tenant) {
                throw new \RuntimeException('Tenant not found');
            }

            $periodType = $periodType ?: ($tenant['invoice_period'] ?? 'month');

            // Get all collected fees in period
            $fees = $this->feeModel->getCollectedForPeriod($tenantId, $periodStart, $periodEnd);
            if (empty($fees)) {
                throw new \RuntimeException('Geen te factureren fees in deze periode');
            }

            // Calculate totals
            $grossTotal = 0;
            $feeTotal = 0;
            foreach ($fees as $fee) {
                $grossTotal += (int) ($fee['gross_amount_cents'] ?? 0);
                $feeTotal   += (int) ($fee['fee_amount_cents'] ?? 0);
            }

            // BTW calculation (21% over fee_total)
            $btwPercentage = PLATFORM_FEE_BTW_PERCENTAGE; // 21.00
            $btwAmount = (int) floor($feeTotal * $btwPercentage / 100);
            $totalInclBtw = $feeTotal + $btwAmount;

            $transactionCount = count($fees);

            // Create invoice
            $invoiceNumber = $this->invoiceModel->generateInvoiceNumber();
            $invoiceId = $this->invoiceModel->create([
                'tenant_id'            => $tenantId,
                'invoice_number'       => $invoiceNumber,
                'period_start'         => $periodStart,
                'period_end'           => $periodEnd,
                'period_type'          => $periodType,
                'transaction_count'    => $transactionCount,
                'gross_total_cents'    => $grossTotal,
                'fee_total_cents'      => $feeTotal,
                'btw_percentage'       => $btwPercentage,
                'btw_amount_cents'     => $btwAmount,
                'total_incl_btw_cents' => $totalInclBtw,
                'status'               => 'draft',
            ]);

            // Link fees to invoice (batch update status to 'invoiced')
            $feeIds = array_column($fees, 'id');
            $linkedCount = $this->feeModel->linkToInvoice($feeIds, $invoiceId);

            $this->db->commit();

            // Audit (after commit)
            $audit = new Audit($this->db);
            $audit->log(
                0, // platform-level
                currentUserId(),
                'invoice.generated',
                'invoice',
                $invoiceId,
                [
                    'tenant_id'          => $tenantId,
                    'invoice_number'     => $invoiceNumber,
                    'period'             => "$periodStart → $periodEnd",
                    'fee_total_cents'    => $feeTotal,
                    'btw_amount_cents'   => $btwAmount,
                    'total_incl_btw'     => $totalInclBtw,
                    'transactions_count' => $transactionCount,
                    'fees_linked'       => $linkedCount,
                ]
            );

            return [
                'invoice_id'      => $invoiceId,
                'invoice_number'  => $invoiceNumber,
                'totals'          => [
                    'gross_total'   => $grossTotal,
                    'fee_total'     => $feeTotal,
                    'btw_amount'    => $btwAmount,
                    'total_incl_btw' => $totalInclBtw,
                    'tx_count'      => $transactionCount,
                ],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Generate invoices for ALL active tenants with Mollie Connect
     *
     * @return array<int, array{tenant_id: int, tenant_name: string, invoice_id: int, invoice_number: string}>
     */
    public function generateAllInvoices(string $periodStart, string $periodEnd): array
    {
        $results = [];

        // Get all active tenants with Mollie Connect
        $stmt = $this->db->prepare(
            "SELECT `id`, `name` FROM `tenants`
             WHERE `mollie_connect_status` = 'active'
               AND `is_active` = 1
             ORDER BY `id` ASC"
        );
        $stmt->execute();
        $tenants = $stmt->fetchAll();

        foreach ($tenants as $tenant) {
            try {
                $result = $this->generateMonthlyInvoice(
                    (int) $tenant['id'],
                    $periodStart,
                    $periodEnd
                );
                $results[] = [
                    'tenant_id'       => (int) $tenant['id'],
                    'tenant_name'     => $tenant['name'],
                    'invoice_id'      => $result['invoice_id'],
                    'invoice_number'  => $result['invoice_number'],
                    'totals'          => $result['totals'],
                ];
            } catch (\RuntimeException $e) {
                // Log error but continue with other tenants
                error_log("Invoice generation failed for tenant {$tenant['id']} ({$tenant['name']}): " . $e->getMessage());
                continue;
            }
        }

        return $results;
    }

    /**
     * Get fee statistics for a tenant (for UI)
     *
     * @return array{today: int, this_month: int, last_invoice_date: string|null, next_invoice_date: string|null}
     */
    public function getTenantFeeStats(int $tenantId): array
    {
        // Today's fees
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(`fee_amount_cents`), 0)
             FROM `platform_fees`
             WHERE `tenant_id` = :tid
               AND DATE(`created_at`) = CURDATE()
               AND `status` IN ('collected', 'invoiced', 'settled')"
        );
        $stmt->execute([':tid' => $tenantId]);
        $today = (int) $stmt->fetchColumn();

        // This month's fees
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(`fee_amount_cents`), 0)
             FROM `platform_fees`
             WHERE `tenant_id` = :tid
               AND `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND `status` IN ('collected', 'invoiced', 'settled')"
        );
        $stmt->execute([':tid' => $tenantId]);
        $thisMonth = (int) $stmt->fetchColumn();

        // Last invoice date
        $stmt = $this->db->prepare(
            "SELECT `period_end` FROM `platform_invoices`
             WHERE `tenant_id` = :tid AND `status` != 'cancelled'
             ORDER BY `created_at` DESC LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId]);
        $lastInvoiceDate = $stmt->fetchColumn();

        // Next invoice date (based on invoice_period)
        $tenant = $this->tenantModel->findById($tenantId);
        $nextInvoiceDate = null;
        if ($tenant) {
            $period = $tenant['invoice_period'] ?? 'month';
            if ($period === 'month') {
                $nextInvoiceDate = date('Y-m-t', strtotime('first day of next month'));
            } else {
                // Weekly: next Sunday
                $nextInvoiceDate = date('Y-m-d', strtotime('next sunday'));
            }
        }

        return [
            'today'              => $today,
            'this_month'         => $thisMonth,
            'last_invoice_date'  => $lastInvoiceDate,
            'next_invoice_date'  => $nextInvoiceDate,
        ];
    }

    /**
     * Get platform-wide fee totals for dashboard
     *
     * @return array{today_fee: int, month_fee: int, all_fee: int}
     */
    public function getPlatformTotals(): array
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN DATE(`created_at`) = CURDATE() THEN `fee_amount_cents` ELSE 0 END), 0) AS today,
                COALESCE(SUM(CASE WHEN `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN `fee_amount_cents` ELSE 0 END), 0) AS month,
                COALESCE(SUM(`fee_amount_cents`), 0) AS all_time
            FROM `platform_fees`
            WHERE `status` IN ('collected', 'invoiced', 'settled')
        ";
        $stmt = $this->db->query($sql);
        $row = $stmt->fetch();

        return [
            'today_fee'  => (int) ($row['today'] ?? 0),
            'month_fee'  => (int) ($row['month'] ?? 0),
            'all_fee'    => (int) ($row['all_time'] ?? 0),
        ];
    }

    /**
     * Get per-tenant fee totals for super-admin overview
     *
     * @return array<int, array{tenant_id: int, tenant_name: string, fee_total: int, gross_total: int, tx_count: int}>
     */
    public function getPerTenantTotals(?string $periodStart = null, ?string $periodEnd = null): array
    {
        $where = '';
        $params = [];

        if ($periodStart && $periodEnd) {
            $where = ' WHERE pf.`created_at` >= :start AND pf.`created_at` < :end + INTERVAL 1 DAY ';
            $params[':start'] = $periodStart;
            $params[':end'] = $periodEnd;
        }

        $sql = "
            SELECT t.`id` AS tenant_id,
                   t.`name` AS tenant_name,
                   t.`platform_fee_percentage`,
                   COALESCE(SUM(pf.`fee_amount_cents`), 0) AS fee_total,
                   COALESCE(SUM(pf.`gross_amount_cents`), 0) AS gross_total,
                   COUNT(pf.`id`) AS tx_count
            FROM `tenants` t
            LEFT JOIN `platform_fees` pf ON pf.`tenant_id` = t.`id` AND pf.`status` IN ('collected', 'invoiced', 'settled')
            {$where}
            GROUP BY t.`id`, t.`name`, t.`platform_fee_percentage`
            ORDER BY fee_total DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Recalculate fee for a transaction (emergency use only)
     * Normally NOT needed — fee comes from Mollie
     *
     * @deprecated Use Mollie's applicationFee as truth
     */
    public function recalculateFeeForTransaction(int $transactionId, ?float $overridePercentage = null): int
    {
        $tx = $this->db->prepare(
            'SELECT `tenant_id`, `final_total_cents` FROM `transactions` WHERE `id` = :id'
        )->execute([':id' => $transactionId])->fetch();

        if (!$tx) {
            throw new \RuntimeException('Transaction not found');
        }

        $tenant = $this->tenantModel->findById((int) $tx['tenant_id']);
        if (!$tenant) {
            throw new \RuntimeException('Tenant not found');
        }

        $percentage = $overridePercentage ?? (float) $tenant['platform_fee_percentage'];
        $minCents = (int) $tenant['platform_fee_min_cents'];

        return $this->calculateFee((int) $tx['final_total_cents'], $percentage, $minCents);
    }
}
