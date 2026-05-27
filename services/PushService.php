<?php
declare(strict_types=1);

require_once __DIR__ . '/FCMClient.php';

/**
 * Push Service - Uses Firebase Cloud Messaging
 */
class PushService
{
    private $db;
    private $fcm;

    public function __construct($db)
    {
        $this->db = $db;
        $this->fcm = new FCMClient();
    }

    // ── Subscription counts ─────────────────────────────────────────

    /**
     * Count users with FCM tokens for a tenant (used by admin/push view)
     */
    public function getSubscriptionCount(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get FCM token for a specific user
     */
    public function getSubscriptionForUser(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT fcm_token FROM users WHERE id = :user_id AND tenant_id = :tenant_id AND fcm_token IS NOT NULL"
        );
        $stmt->execute([':user_id' => $userId, ':tenant_id' => $tenantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get all subscribers (users with FCM tokens) for a tenant
     */
    public function getSubscriptionsForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, email, first_name, last_name, fcm_token FROM users WHERE tenant_id = :tenant_id AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Token storage ───────────────────────────────────────────────

    /**
     * Store FCM token for user
     */
    public function storeFcmToken(int $userId, string $fcmToken): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET fcm_token = :token WHERE id = :user_id");
        return $stmt->execute([
            ':token' => $fcmToken,
            ':user_id' => $userId
        ]);
    }

    /**
     * Remove FCM token for user (guest disables push notifications)
     */
    public function removeFcmToken(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET fcm_token = NULL WHERE id = :user_id");
        return $stmt->execute([
            ':user_id' => $userId
        ]);
    }

    // ── Send to individual user ─────────────────────────────────────

    /**
     * Send push notification to a single user via FCM
     * Returns array with sent/failed counts (compatible with old API)
     */
    public function sendNotification(int $userId, string $title, string $body, array $data = []): array
    {
        $stmt = $this->db->prepare("SELECT u.fcm_token, t.name AS tenant_name, t.logo_path FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id WHERE u.id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || empty($result['fcm_token'])) {
            return ['sent' => 0, 'failed' => 1];
        }

        // Inject tenant branding into data payload
        $brandedData = array_merge($data, [
            'tenant_name' => $result['tenant_name'] ?? '',
            'icon'        => !empty($result['logo_path']) ? $result['logo_path'] : '/icons/favicon.png',
        ]);

        $response = $this->fcm->sendMessage($result['fcm_token'], $title, $body, $brandedData);

        if ($response !== false) {
            return ['sent' => 1, 'failed' => 0];
        }
        return ['sent' => 0, 'failed' => 1];
    }

    // ── Broadcast to all tenant users ───────────────────────────────

    /**
     * Broadcast notification to all users of a tenant
     * Returns array with sent/failed/total_subscriptions counts
     */
    public function broadcastNotification(int $tenantId, string $title, string $body, array $data = []): array
    {
        // Fetch tenant branding
        $tenantStmt = $this->db->prepare("SELECT name, logo_path FROM tenants WHERE id = :id");
        $tenantStmt->execute([':id' => $tenantId]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        $tenantName = $tenant['name'] ?? '';
        $tenantIcon = !empty($tenant['logo_path']) ? $tenant['logo_path'] : '/icons/favicon.png';

        // Inject tenant branding into data payload
        $brandedData = array_merge($data, [
            'tenant_name' => $tenantName,
            'icon'        => $tenantIcon,
        ]);

        $stmt = $this->db->prepare(
            "SELECT id, fcm_token FROM users WHERE tenant_id = :tenant_id AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            $result = $this->fcm->sendMessage($user['fcm_token'], $title, $body, $brandedData);
            if ($result !== false) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'total_subscriptions' => count($users)
        ];
    }
}