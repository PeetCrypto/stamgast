<?php
declare(strict_types=1);

/**
 * User Model
 * Data access layer for the users table
 */

class User
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM `users` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find user by email within a tenant (email is unique per tenant)
     */
    public function findByEmail(string $email, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `users` WHERE `email` = :email AND `tenant_id` = :tenant_id LIMIT 1'
        );
        $stmt->execute([
            ':email'     => $email,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all users for a tenant
     * @return array<int, array>
     */
    public function getByTenant(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT `id`, `email`, `role`, `first_name`, `last_name`, `birthdate`,
                    `photo_url`, `photo_status`, `last_activity`, `created_at`
             FROM `users`
             WHERE `tenant_id` = :tenant_id
             ORDER BY `created_at` DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count users for a tenant
     */
    public function countByTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `users` WHERE `tenant_id` = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Create a new user
     * @return int The new user ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `users`
             (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`, `photo_url`, `photo_status`)
             VALUES
             (:tenant_id, :email, :password_hash, :role, :first_name, :last_name, :birthdate, :photo_url, :photo_status)'
        );

        $stmt->execute([
            ':tenant_id'     => $data['tenant_id'],
            ':email'         => $data['email'],
            ':password_hash' => $data['password_hash'],
            ':role'          => $data['role'] ?? 'guest',
            ':first_name'    => $data['first_name'],
            ':last_name'     => $data['last_name'],
            ':birthdate'     => $data['birthdate'] ?? null,
            ':photo_url'     => $data['photo_url'] ?? null,
            ':photo_status'  => $data['photo_status'] ?? 'unvalidated',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a user's role
     */
    public function updateRole(int $userId, string $role): bool
    {
        $stmt = $this->db->prepare('UPDATE `users` SET `role` = :role WHERE `id` = :id');
        return $stmt->execute([
            ':role' => $role,
            ':id'   => $userId,
        ]);
    }

    /**
     * Update user profile photo
     */
    public function updatePhoto(int $userId, string $photoUrl, string $status = 'unvalidated'): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `users` SET `photo_url` = :photo_url, `photo_status` = :photo_status WHERE `id` = :id'
        );
        return $stmt->execute([
            ':photo_url'    => $photoUrl,
            ':photo_status' => $status,
            ':id'           => $userId,
        ]);
    }

    /**
     * Update photo validation status
     */
    public function updatePhotoStatus(int $userId, string $status): bool
    {
        $allowed = ['unvalidated', 'validated', 'blocked'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE `users` SET `photo_status` = :status WHERE `id` = :id');
        return $stmt->execute([
            ':status' => $status,
            ':id'     => $userId,
        ]);
    }

    /**
     * Update user's last activity timestamp
     */
    public function updateLastActivity(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE `users` SET `last_activity` = NOW() WHERE `id` = :id');
        return $stmt->execute([':id' => $userId]);
    }

    /**
     * Update push token for web push notifications
     */
    public function updatePushToken(int $userId, ?string $token): bool
    {
        $stmt = $this->db->prepare('UPDATE `users` SET `push_token` = :token WHERE `id` = :id');
        return $stmt->execute([
            ':token' => $token,
            ':id'    => $userId,
        ]);
    }

    /**
     * Get user's safe public data (no password_hash)
     */
    public function getPublicProfile(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT `id`, `tenant_id`, `email`, `role`, `first_name`, `last_name`,
                    `birthdate`, `photo_url`, `photo_status`, `last_activity`, `created_at`
             FROM `users` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Calculate user's age from birthdate
     */
    public function calculateAge(int $userId): ?int
    {
        $stmt = $this->db->prepare('SELECT `birthdate` FROM `users` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $birthdate = $stmt->fetchColumn();

        if (!$birthdate) {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date) {
            return null;
        }

        return (int) $date->diff(new DateTime())->y;
    }

    /**
     * Check if email already exists within a tenant
     */
    public function emailExists(string $email, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `users` WHERE `email` = :email AND `tenant_id` = :tenant_id'
        );
        $stmt->execute([
            ':email'     => $email,
            ':tenant_id' => $tenantId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
