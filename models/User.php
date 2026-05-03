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
     * Find user by email across ALL tenants (for superadmin login)
     * Superadmins have tenant_id = NULL, so tenant-filtered search won't find them
     */
    public function findByEmailGlobal(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `users` WHERE `email` = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
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
     * Count users for a tenant (excludes superadmin - they are platform-level, not tenant-level)
     */
    public function countByTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `users` WHERE `tenant_id` = :tenant_id AND `role` != \'superadmin\'');
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Create a new user
     * @return int The new user ID
     */
    public function create(array $data): int
    {
        // Staff (admin, bartender) are always active — only guests need verification
        $role = $data['role'] ?? 'guest';
        $verificationRequired = $data['verification_required'] ?? true;

        if ($role !== 'guest') {
            $accountStatus = 'active'; // Staff is altijd active
        } elseif (!$verificationRequired) {
            $accountStatus = 'active'; // Toggle uit → gast meteen active
        } else {
            $accountStatus = 'unverified'; // Toggle aan → gast moet geverifieerd worden
        }

        $stmt = $this->db->prepare(
            'INSERT INTO `users`
             (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`, `photo_url`, `photo_status`, `account_status`)
             VALUES
             (:tenant_id, :email, :password_hash, :role, :first_name, :last_name, :birthdate, :photo_url, :photo_status, :account_status)'
        );

        $stmt->execute([
            ':tenant_id'     => $data['tenant_id'] ?? null,
            ':email'         => $data['email'],
            ':password_hash' => $data['password_hash'],
            ':role'          => $role,
            ':first_name'    => $data['first_name'],
            ':last_name'     => $data['last_name'],
            ':birthdate'     => $data['birthdate'] ?? null,
            ':photo_url'     => $data['photo_url'] ?? null,
            ':photo_status'  => $data['photo_status'] ?? 'unvalidated',
            ':account_status' => $accountStatus,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a user's role
     * Also updates account_status: staff (admin, bartender) are always active,
     * guests reverted from staff keep their current status.
     */
    public function updateRole(int $userId, string $role): bool
    {
        // When promoting to staff, auto-activate the account
        if ($role !== 'guest') {
            $stmt = $this->db->prepare(
                'UPDATE `users` SET `role` = :role, `account_status` = \'active\' WHERE `id` = :id'
            );
        } else {
            $stmt = $this->db->prepare('UPDATE `users` SET `role` = :role WHERE `id` = :id');
        }

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

    /**
     * Update a user's email address
     */
    public function updateEmail(int $userId, string $newEmail): bool
    {
        $stmt = $this->db->prepare('UPDATE `users` SET `email` = :email WHERE `id` = :id');
        return $stmt->execute([
            ':email' => $newEmail,
            ':id'    => $userId,
        ]);
    }

    /**
     * Update a user's password
     */
    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE `users` SET `password_hash` = :password_hash WHERE `id` = :id');
        return $stmt->execute([
            ':password_hash' => $passwordHash,
            ':id' => $userId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Gated Onboarding — Account Status Methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Get account_status for a user (fallback: 'unverified')
     */
    public function getAccountStatus(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT `account_status` FROM `users` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $result = $stmt->fetchColumn();
        return $result ?: 'unverified';
    }

    /**
     * Update account_status + audit fields
     */
    public function updateAccountStatus(int $userId, string $status, ?int $changedBy = null, ?string $reason = null): bool
    {
        $allowed = ['unverified', 'active', 'suspended'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $sql = 'UPDATE `users` SET `account_status` = :status';
        $params = [':status' => $status, ':id' => $userId];

        if ($status === 'active') {
            $sql .= ', `verified_at` = NOW(), `verified_by` = :changed_by, `verified_birthdate` = :birthdate';
            $params[':changed_by'] = $changedBy;
            $params[':birthdate'] = $reason; // For active, reason field carries birthdate
        } elseif ($status === 'suspended') {
            $sql .= ', `suspended_reason` = :reason, `suspended_at` = NOW(), `suspended_by` = :changed_by';
            $params[':reason'] = $reason;
            $params[':changed_by'] = $changedBy;
        } elseif ($status === 'active' && $changedBy !== null) {
            // Unsuspend — clear suspension fields
            $sql .= ', `suspended_reason` = NULL, `suspended_at` = NULL, `suspended_by` = NULL';
        }

        $sql .= ' WHERE `id` = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Verify a user: check birthdate match, update to active, log attempt
     * Returns array with success bool and details
     */
    public function verifyUser(int $userId, int $verifiedBy, string $birthdateSeen): array
    {
        // Fetch user to compare birthdate
        $user = $this->findById($userId);
        if ($user === null) {
            return ['success' => false, 'error' => 'Gebruiker niet gevonden'];
        }

        $storedBirthdate = $user['birthdate'] ?? null;
        $birthdateMatch = ($storedBirthdate !== null && $storedBirthdate === $birthdateSeen);
        $statusBefore = $user['account_status'] ?? 'unverified';

        // Log attempt in verification_attempts (always, regardless of match)
        $this->logVerificationAttempt(
            (int) $user['tenant_id'],
            $userId,
            $verifiedBy,
            $birthdateSeen,
            $birthdateMatch,
            $statusBefore,
            $birthdateMatch ? 'active' : $statusBefore
        );

        if (!$birthdateMatch) {
            // Count remaining attempts for this guest
            $attemptsUsed = $this->countGuestVerificationAttempts($userId);
            return [
                'success' => false,
                'error' => 'Geboortedatum komt niet overeen met registratie. Vraag de gast het ID opnieuw te controleren.',
                'code' => 'BIRTHDATE_MISMATCH',
                'data' => [
                    'verified' => false,
                    'birthdate_match' => false,
                    'attempts_remaining' => max(0, 2 - $attemptsUsed), // Default max 2
                ],
            ];
        }

        // Birthdate matches — activate account
        $this->updateAccountStatus($userId, 'active', $verifiedBy, $birthdateSeen);

        return [
            'success' => true,
            'data' => [
                'verified' => true,
                'user_id' => $userId,
                'birthdate_match' => true,
                'account_status' => 'active',
            ],
        ];
    }

    /**
     * Suspend a user account
     */
    public function suspendUser(int $userId, int $suspendedBy, string $reason): bool
    {
        return $this->updateAccountStatus($userId, 'suspended', $suspendedBy, $reason);
    }

    /**
     * Unsuspend a user account (back to active)
     */
    public function unsuspendUser(int $userId, int $unsuspendedBy): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `users` SET `account_status` = \'active\', `suspended_reason` = NULL, `suspended_at` = NULL, `suspended_by` = NULL WHERE `id` = :id'
        );
        return $stmt->execute([':id' => $userId]);
    }

    /**
     * Count verifications by a bartender in a time window (rate limiting)
     */
    public function countVerificationsInWindow(int $tenantId, int $bartenderId, int $seconds = 3600): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `verification_attempts`
             WHERE `tenant_id` = :tenant_id
               AND `verified_by` = :bartender_id
               AND `birthdate_match` = 1
               AND `created_at` >= DATE_SUB(NOW(), INTERVAL :seconds SECOND)'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':bartender_id', $bartenderId, PDO::PARAM_INT);
        $stmt->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Count verification attempts for a guest in past N hours (per-guest limit)
     */
    public function countGuestVerificationAttempts(int $userId, int $hours = 24): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `verification_attempts`
             WHERE `user_id` = :user_id
               AND `created_at` >= DATE_SUB(NOW(), INTERVAL :hours HOUR)'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Log a verification attempt (audit trail)
     */
    public function logVerificationAttempt(
        int $tenantId,
        int $userId,
        int $verifiedBy,
        string $birthdateSeen,
        bool $birthdateMatch,
        string $statusBefore,
        string $statusAfter
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO `verification_attempts`
             (`tenant_id`, `user_id`, `verified_by`, `birthdate_seen`, `birthdate_match`, `status_before`, `status_after`, `ip_address`)
             VALUES
             (:tenant_id, :user_id, :verified_by, :birthdate_seen, :birthdate_match, :status_before, :status_after, :ip_address)'
        );
        $stmt->execute([
            ':tenant_id'      => $tenantId,
            ':user_id'        => $userId,
            ':verified_by'    => $verifiedBy,
            ':birthdate_seen' => $birthdateSeen,
            ':birthdate_match'=> (int) $birthdateMatch,
            ':status_before'  => $statusBefore,
            ':status_after'   => $statusAfter,
            ':ip_address'     => getClientIP(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    // ─────────────────────────────────────────────────────────────
    // Password Reset Methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Create a password reset token for a user
     * Invalidates any previous unused tokens for this user
     * @return string The generated token
     */
    public function createResetToken(int $userId, string $email, int $tenantId): string
    {
        // Invalidate any existing unused tokens for this user
        $this->db->prepare(
            'UPDATE `password_resets` SET `used_at` = NOW() WHERE `user_id` = :user_id AND `used_at` IS NULL'
        )->execute([':user_id' => $userId]);

        // Generate a secure token
        $token = bin2hex(random_bytes(32));

        // Store the token with 1-hour expiry
        $stmt = $this->db->prepare(
            'INSERT INTO `password_resets` (`user_id`, `email`, `tenant_id`, `token`, `expires_at`)
             VALUES (:user_id, :email, :tenant_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':email'     => $email,
            ':tenant_id' => $tenantId,
            ':token'     => $token,
        ]);

        return $token;
    }

    /**
     * Find a valid (non-expired, non-used) reset token
     * @return array|null Token record with user info, or null
     */
    public function findValidResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pr.*, u.first_name, u.last_name, u.role
             FROM `password_resets` pr
             JOIN `users` u ON u.id = pr.user_id
             WHERE pr.token = :token
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Mark a reset token as used
     */
    public function consumeResetToken(string $token): void
    {
        $this->db->prepare(
            'UPDATE `password_resets` SET `used_at` = NOW() WHERE `token` = :token'
        )->execute([':token' => $token]);
    }
}
