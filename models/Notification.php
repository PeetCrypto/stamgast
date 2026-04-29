<?php
declare(strict_types=1);

/**
 * Notification Model
 * Data access layer for the notifications table (Guest Inbox)
 *
 * Supports soft-delete (deleted_at) so guests can dismiss items without data loss.
 */

class Notification
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new notification
     *
     * @param array $data {
     *   tenant_id: int, user_id: int, transaction_id?: int, type: string,
     *   icon: string, title: string, body: string, color: string, points_earned?: int
     * }
     * @return int The new notification ID
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `notifications`
                (`tenant_id`, `user_id`, `transaction_id`, `type`,
                 `icon`, `title`, `body`, `color`, `points_earned`)
             VALUES
                (:tenant_id, :user_id, :transaction_id, :type,
                 :icon, :title, :body, :color, :points_earned)'
        );

        $stmt->execute([
            ':tenant_id'       => $data['tenant_id'],
            ':user_id'         => $data['user_id'],
            ':transaction_id'  => $data['transaction_id'] ?? null,
            ':type'            => $data['type'],
            ':icon'            => $data['icon'] ?? '📋',
            ':title'           => $data['title'],
            ':body'            => $data['body'],
            ':color'           => $data['color'] ?? 'var(--text-secondary)',
            ':points_earned'   => $data['points_earned'] ?? 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get notifications for a user (excluding soft-deleted), paginated
     *
     * @return array{notifications: array, total: int, page: int, limit: int, unread_count: int}
     */
    public function getByUser(int $userId, int $tenantId, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(max(1, $limit), 100);
        $offset = ($page - 1) * $limit;

        // Count total active notifications
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `notifications`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id AND `deleted_at` IS NULL'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $total = (int) $stmt->fetchColumn();

        // Count unread
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `notifications`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id
               AND `deleted_at` IS NULL AND `is_read` = 0'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $unreadCount = (int) $stmt->fetchColumn();

        // Fetch page
        $stmt = $this->db->prepare(
            'SELECT * FROM `notifications`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id AND `deleted_at` IS NULL
             ORDER BY `created_at` DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'         => $total,
            'page'          => $page,
            'limit'         => $limit,
            'unread_count'  => $unreadCount,
        ];
    }

    /**
     * Find a notification by ID (must belong to user + tenant)
     */
    public function findById(int $id, int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `notifications`
             WHERE `id` = :id AND `user_id` = :user_id AND `tenant_id` = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id'        => $id,
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Soft-delete a notification
     *
     * @param int $id       Notification ID
     * @param int $userId   Owner user ID (security: ensures guest can only delete own)
     * @param int $tenantId Tenant ID
     * @return bool True if deleted, false if not found or already deleted
     */
    public function softDelete(int $id, int $userId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `notifications`
             SET `deleted_at` = NOW()
             WHERE `id` = :id
               AND `user_id` = :user_id
               AND `tenant_id` = :tenant_id
               AND `deleted_at` IS NULL'
        );
        $stmt->execute([
            ':id'        => $id,
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a notification as read
     *
     * @return bool True if updated, false if not found or already read
     */
    public function markAsRead(int $id, int $userId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE `notifications`
             SET `is_read` = 1
             WHERE `id` = :id
               AND `user_id` = :user_id
               AND `tenant_id` = :tenant_id
               AND `is_read` = 0'
        );
        $stmt->execute([
            ':id'        => $id,
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all notifications as read for a user
     *
     * @return int Number of notifications marked as read
     */
    public function markAllAsRead(int $userId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE `notifications`
             SET `is_read` = 1
             WHERE `user_id` = :user_id
               AND `tenant_id` = :tenant_id
               AND `is_read` = 0
               AND `deleted_at` IS NULL'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Get unread count for a user (for badge display)
     */
    public function getUnreadCount(int $userId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `notifications`
             WHERE `user_id` = :user_id
               AND `tenant_id` = :tenant_id
               AND `is_read` = 0
               AND `deleted_at` IS NULL'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        return (int) $stmt->fetchColumn();
    }
}
