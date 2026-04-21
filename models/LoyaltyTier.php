<?php
declare(strict_types=1);

/**
 * LoyaltyTier Model
 * Data access layer for the loyalty_tiers table
 */

class LoyaltyTier
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find tier by ID within a tenant
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `loyalty_tiers` WHERE `id` = :id AND `tenant_id` = :tenant_id LIMIT 1'
        );
        $stmt->execute([
            ':id'        => $id,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all tiers for a tenant, ordered by min_deposit_cents ASC
     *
     * @return array<int, array>
     */
    public function getByTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `loyalty_tiers`
             WHERE `tenant_id` = :tenant_id
             ORDER BY `min_deposit_cents` ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Create a new tier
     * @return int The new tier ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `loyalty_tiers`
             (`tenant_id`, `name`, `min_deposit_cents`, `alcohol_discount_perc`, `food_discount_perc`, `points_multiplier`)
             VALUES
             (:tenant_id, :name, :min_deposit_cents, :alcohol_discount_perc, :food_discount_perc, :points_multiplier)'
        );

        $stmt->execute([
            ':tenant_id'            => $data['tenant_id'],
            ':name'                 => $data['name'],
            ':min_deposit_cents'    => $data['min_deposit_cents'] ?? 0,
            ':alcohol_discount_perc' => $data['alcohol_discount_perc'] ?? 0,
            ':food_discount_perc'   => $data['food_discount_perc'] ?? 0,
            ':points_multiplier'    => $data['points_multiplier'] ?? 1.00,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing tier
     */
    public function update(int $id, int $tenantId, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId];

        $allowedFields = [
            'name', 'min_deposit_cents', 'alcohol_discount_perc',
            'food_discount_perc', 'points_multiplier',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = 'UPDATE `loyalty_tiers` SET ' . implode(', ', $fields) . ' WHERE `id` = :id AND `tenant_id` = :tenant_id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a tier
     */
    public function delete(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM `loyalty_tiers` WHERE `id` = :id AND `tenant_id` = :tenant_id'
        );
        return $stmt->execute([
            ':id'        => $id,
            ':tenant_id' => $tenantId,
        ]);
    }

    /**
     * Determine the active tier for a user based on their total deposits
     *
     * Returns the highest tier where min_deposit_cents <= totalDeposits.
     * If no tiers are configured, returns a default tier with no discounts.
     */
    public function determineTier(int $tenantId, int $totalDepositCents): array
    {
        $tiers = $this->getByTenant($tenantId);

        if (empty($tiers)) {
            // Default tier: no discounts, 1x multiplier
            return [
                'id'                   => 0,
                'name'                 => 'Standaard',
                'min_deposit_cents'    => 0,
                'alcohol_discount_perc' => 0.00,
                'food_discount_perc'   => 0.00,
                'points_multiplier'    => 1.00,
            ];
        }

        // Find highest qualifying tier
        $activeTier = $tiers[0]; // lowest tier as fallback
        foreach ($tiers as $tier) {
            if ($totalDepositCents >= (int) $tier['min_deposit_cents']) {
                $activeTier = $tier;
            }
        }

        return $activeTier;
    }
}
