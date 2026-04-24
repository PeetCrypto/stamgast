<?php
declare(strict_types=1);

/**
 * Tenant Model
 * Data access layer for the tenants table
 */

class Tenant
{
    private PDO $db;

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
     * Create a new tenant (including NAW fields)
     */
    public function create(array $data): int
    {
        $uuid = $data['uuid'] ?? generateUUID();
        $secretKey = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO `tenants`
             (`uuid`, `name`, `slug`, `brand_color`, `secondary_color`, `secret_key`,
              `mollie_status`, `whitelisted_ips`, `contact_name`, `contact_email`,
              `phone`, `address`, `postal_code`, `city`, `country`)
             VALUES
             (:uuid, :name, :slug, :brand_color, :secondary_color, :secret_key,
              :mollie_status, :whitelisted_ips, :contact_name, :contact_email,
              :phone, :address, :postal_code, :city, :country)'
        );

        $stmt->execute([
            ':uuid'             => $uuid,
            ':name'             => $data['name'],
            ':slug'             => $data['slug'],
            ':brand_color'      => $data['brand_color'] ?? '#FFC107',
            ':secondary_color'  => $data['secondary_color'] ?? '#FF9800',
            ':secret_key'       => $secretKey,
            ':mollie_status'    => $data['mollie_status'] ?? 'mock',
            ':whitelisted_ips'  => $data['whitelisted_ips'] ?? null,
            ':contact_name'     => $data['contact_name'] ?? null,
            ':contact_email'    => $data['contact_email'] ?? null,
            ':phone'            => $data['phone'] ?? null,
            ':address'          => $data['address'] ?? null,
            ':postal_code'      => $data['postal_code'] ?? null,
            ':city'             => $data['city'] ?? null,
            ':country'          => $data['country'] ?? 'Nederland',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a tenant (including NAW fields)
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = [
            'name', 'slug', 'brand_color', 'secondary_color', 'logo_path',
            'mollie_api_key', 'mollie_status', 'whitelisted_ips',
            'is_active',
            'feature_push', 'feature_marketing',
            'contact_name', 'contact_email', 'phone', 'address',
            'postal_code', 'city', 'country',
        ];

        $sets = [];
        $params = [':id' => $id];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
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
