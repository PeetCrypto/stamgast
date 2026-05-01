<?php
declare(strict_types=1);

/**
 * Tenant Model
 * Data access layer for the tenants table
 */

class Tenant
{
    private PDO $db;

    /**
     * Whitelist of updatable tenant fields
     * ⚠️ SECURITY: mollie_api_key is EXPLICITLY EXCLUDED (hufter-proof)
     * Tenant cannot bypass platform fee by changing API keys
     * Platform fee config fields are super-admin only
     */
    private array $allowedFields = [
        'name', 'slug', 'brand_color', 'secondary_color', 'logo_path',
        'whitelisted_ips',
        'is_active',
        'feature_push', 'feature_marketing', 'verification_required',
        // NAW fields
        'contact_name', 'contact_email', 'phone', 'address',
        'postal_code', 'city', 'country',
        // Platform fee configuration (super-admin only)
        'platform_fee_percentage', 'platform_fee_min_cents',
        'mollie_status', 'mollie_connect_id', 'mollie_connect_status',
        'invoice_period', 'btw_number', 'invoice_email', 'platform_fee_note',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find tenant by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM `tenants` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find tenant by UUID
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM `tenants` WHERE `uuid` = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find tenant by slug
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM `tenants` WHERE `slug` = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find tenant by email (contact_email)
     */
    public function findByEmail(string $email, ?int $tenantId = null): ?array
    {
        $sql = 'SELECT * FROM `tenants` WHERE `contact_email` = :email';
        $params = [':email' => $email];

        if ($tenantId !== null) {
            $sql .= ' AND `id` = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all tenants (for super-admin)
     */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM `tenants` ORDER BY `created_at` DESC');
        return $stmt->fetchAll();
    }

    /**
     * Get all tenants with user count per tenant
     */
    public function getAllWithUserCount(): array
    {
        $stmt = $this->db->query(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id`) AS user_count,
                    (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id` AND u.`role` = \'guest\') AS guest_count,
                    (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id` AND u.`role` IN (\'admin\',\'bartender\')) AS staff_count
             FROM `tenants` t
             ORDER BY t.`created_at` DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Create a new tenant (including NAW + fee config fields)
     * Fee config gets safe defaults
     */
    public function create(array $data): int
    {
        $uuid = $data['uuid'] ?? generateUUID();
        $secretKey = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO `tenants`
             (`uuid`, `name`, `slug`, `brand_color`, `secondary_color`, `secret_key`,
              `mollie_status`, `whitelisted_ips`,
              `platform_fee_percentage`, `platform_fee_min_cents`,
              `mollie_connect_status`, `invoice_period`,
              `contact_name`, `contact_email`, `phone`, `address`,
              `postal_code`, `city`, `country`, `platform_fee_note`)
             VALUES
             (:uuid, :name, :slug, :brand_color, :secondary_color, :secret_key,
              :mollie_status, :whitelisted_ips,
              :platform_fee_percentage, :platform_fee_min_cents,
              :mollie_connect_status, :invoice_period,
              :contact_name, :contact_email, :phone, :address,
              :postal_code, :city, :country, :platform_fee_note)'
        );

        $stmt->execute([
            ':uuid'                    => $uuid,
            ':name'                    => $data['name'],
            ':slug'                    => $data['slug'],
            ':brand_color'             => $data['brand_color'] ?? '#FFC107',
            ':secondary_color'         => $data['secondary_color'] ?? '#FF9800',
            ':secret_key'              => $secretKey,
            ':mollie_status'           => $data['mollie_status'] ?? 'mock',
            ':whitelisted_ips'         => $data['whitelisted_ips'] ?? null,
            ':platform_fee_percentage' => $data['platform_fee_percentage'] ?? PLATFORM_FEE_DEFAULT_PERCENTAGE,
            ':platform_fee_min_cents'  => $data['platform_fee_min_cents'] ?? PLATFORM_FEE_DEFAULT_MIN_CENTS,
            ':mollie_connect_status'   => $data['mollie_connect_status'] ?? 'none',
            ':invoice_period'          => $data['invoice_period'] ?? 'month',
            ':contact_name'            => $data['contact_name'] ?? null,
            ':contact_email'           => $data['contact_email'] ?? null,
            ':phone'                   => $data['phone'] ?? null,
            ':address'                 => $data['address'] ?? null,
            ':postal_code'             => $data['postal_code'] ?? null,
            ':city'                    => $data['city'] ?? null,
            ':country'                 => $data['country'] ?? 'Nederland',
            ':platform_fee_note'       => $data['platform_fee_note'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a tenant (only allowed fields)
     * Mollie API key is NOT updatable — removed from $allowedFields
     */
    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [':id' => $id];

        foreach ($data as $field => $value) {
            if (in_array($field, $this->allowedFields, true)) {
                $sets[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $value;
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE `tenants` SET ' . implode(', ', $sets) . ' WHERE `id` = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a tenant
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM `tenants` WHERE `id` = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get tenant secret key for HMAC
     */
    public function getSecretKey(int $tenantId): ?string
    {
        $stmt = $this->db->prepare('SELECT `secret_key` FROM `tenants` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Check if a tenant is active (not disabled by superadmin)
     */
    public function isActive(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT `is_active` FROM `tenants` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $result = $stmt->fetchColumn();
        return (bool) $result;
    }

    /**
     * Check if Mollie Connect is active for this tenant
     * Required for any payment processing
     */
    public function isConnectActive(int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT `mollie_connect_status` FROM `tenants` WHERE `id` = :id LIMIT 1"
        );
        $stmt->execute([':id' => $tenantId]);
        $status = $stmt->fetchColumn();
        return $status === 'active';
    }

    /**
     * Get fee configuration for a tenant (safe defaults if null)
     */
    public function getFeeConfig(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT `platform_fee_percentage`, `platform_fee_min_cents`,
                    `invoice_period`, `btw_number`, `invoice_email`, `platform_fee_note`
             FROM `tenants` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch();

        return [
            'percentage'           => (float) ($row['platform_fee_percentage'] ?? PLATFORM_FEE_DEFAULT_PERCENTAGE),
            'min_cents'            => (int) ($row['platform_fee_min_cents'] ?? PLATFORM_FEE_DEFAULT_MIN_CENTS),
            'invoice_period'       => $row['invoice_period'] ?? 'month',
            'btw_number'           => $row['btw_number'] ?? null,
            'invoice_email'        => $row['invoice_email'] ?? null,
            'note'                 => $row['platform_fee_note'] ?? null,
        ];
    }

    /**
     * Get fee summary for a tenant within date range
     * Used in tenant detail view and invoicing
     *
     * @return array{count: int, gross_total: int, fee_total: int, net_total: int}
     */
    public function getFeeSummary(int $tenantId, ?string $start = null, ?string $end = null): array
    {
        $sql = "
            SELECT COUNT(*) AS `count`,
                   COALESCE(SUM(`gross_amount_cents`), 0) AS `gross_total`,
                   COALESCE(SUM(`fee_amount_cents`), 0)   AS `fee_total`,
                   COALESCE(SUM(`net_amount_cents`), 0)   AS `net_total`
            FROM `platform_fees`
            WHERE `tenant_id` = :tenant_id
              AND `status` IN ('collected', 'invoiced', 'settled')
        ";
        $params = [':tenant_id' => $tenantId];

        if ($start !== null) {
            $sql .= " AND `created_at` >= :start";
            $params[':start'] = $start;
        }
        if ($end !== null) {
            $sql .= " AND `created_at` < :end + INTERVAL 1 DAY";
            $params[':end'] = $end;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'count'        => (int) ($row['count'] ?? 0),
            'gross_total'  => (int) ($row['gross_total'] ?? 0),
            'fee_total'    => (int) ($row['fee_total'] ?? 0),
            'net_total'    => (int) ($row['net_total'] ?? 0),
        ];
    }

    /**
     * Get platform overview statistics (super-admin)
     */
    public function getPlatformStats(): array
    {
        $tenants = $this->db->query('SELECT COUNT(*) FROM `tenants`')->fetchColumn();
        $users = $this->db->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
        $revenue = $this->db->query("SELECT COALESCE(SUM(`final_total_cents`), 0) FROM `transactions` WHERE `type` = 'payment'")->fetchColumn();
        $deposits = $this->db->query("SELECT COALESCE(SUM(`final_total_cents`), 0) FROM `transactions` WHERE `type` = 'deposit'")->fetchColumn();

        return [
            'total_tenants' => (int) $tenants,
            'total_users'   => (int) $users,
            'total_revenue' => (int) $revenue,
            'total_deposits' => (int) $deposits,
        ];
    }

    /**
     * Get detailed statistics for a specific tenant (super-admin detail view)
     */
    public function getTenantStats(int $tenantId): array
    {
        // Revenue from payments
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(`final_total_cents`), 0)
             FROM `transactions`
             WHERE `tenant_id` = :tid AND `type` = 'payment'"
        );
        $stmt->execute([':tid' => $tenantId]);
        $revenue = (int) $stmt->fetchColumn();

        // Total deposits
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(`final_total_cents`), 0)
             FROM `transactions`
             WHERE `tenant_id` = :tid AND `type` = 'deposit'"
        );
        $stmt->execute([':tid' => $tenantId]);
        $deposits = (int) $stmt->fetchColumn();

        // Total balance across all wallets (excluding super-admins)
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(w.`balance_cents`), 0)
             FROM `wallets` w
             JOIN `users` u ON w.`user_id` = u.`id`
             WHERE w.`tenant_id` = :tid AND u.`role` != \'superadmin\''
        );
        $stmt->execute([':tid' => $tenantId]);
        $totalBalance = (int) $stmt->fetchColumn();

        // Total points across all wallets (excluding super-admins)
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(w.`points_cents`), 0)
             FROM `wallets` w
             JOIN `users` u ON w.`user_id` = u.`id`
             WHERE w.`tenant_id` = :tid AND u.`role` != \'superadmin\''
        );
        $stmt->execute([':tid' => $tenantId]);
        $totalPoints = (int) $stmt->fetchColumn();

        // User counts per role
        $stmt = $this->db->prepare(
            'SELECT `role`, COUNT(*) as cnt
             FROM `users`
             WHERE `tenant_id` = :tid
             GROUP BY `role`'
        );
        $stmt->execute([':tid' => $tenantId]);
        $roleCounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $roleCounts[$row['role']] = (int) $row['cnt'];
        }

        // Total transaction count
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `transactions` WHERE `tenant_id` = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $transactionCount = (int) $stmt->fetchColumn();

        // Transaction count today
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `transactions`
             WHERE `tenant_id` = :tid AND DATE(`created_at`) = CURDATE()"
        );
        $stmt->execute([':tid' => $tenantId]);
        $todayTransactions = (int) $stmt->fetchColumn();

        // Revenue today
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(`final_total_cents`), 0)
             FROM `transactions`
             WHERE `tenant_id` = :tid AND `type` = 'payment' AND DATE(`created_at`) = CURDATE()"
        );
        $stmt->execute([':tid' => $tenantId]);
        $todayRevenue = (int) $stmt->fetchColumn();

        return [
            'revenue'           => $revenue,
            'deposits'          => $deposits,
            'total_balance'     => $totalBalance,
            'total_points'      => $totalPoints,
            'user_counts'       => $roleCounts,
            'total_users'       => array_sum($roleCounts),
            'transaction_count' => $transactionCount,
            'today_transactions' => $todayTransactions,
            'today_revenue'     => $todayRevenue,
        ];
    }

    /**
     * Get users for a tenant with wallet info (for detail view)
     */
    public function getUsersWithWallets(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.`id`, u.`email`, u.`role`, u.`first_name`, u.`last_name`,
                    u.`birthdate`, u.`last_activity`, u.`created_at`,
                    w.`balance_cents`, w.`points_cents`
             FROM `users` u
             LEFT JOIN `wallets` w ON w.`user_id` = u.`id`
             WHERE u.`tenant_id` = :tid
             ORDER BY u.`created_at` DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
