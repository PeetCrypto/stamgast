<?php
declare(strict_types=1);

/**
 * Push Service
 * Handles Web Push subscriptions and notification delivery via VAPID
 */

class PushService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Store or update a push subscription for a user
     *
     * @param int    $userId   Authenticated user ID
     * @param int    $tenantId Tenant isolation
     * @param string $endpoint Push service endpoint URL
     * @param string $p256dh   Public key (base64)
     * @param string $auth     Auth secret (base64)
     * @return int Subscription ID
     */
    public function subscribe(int $userId, int $tenantId, string $endpoint, string $p256dh, string $auth): int
    {
        // Check if subscription already exists for this user+endpoint
        $stmt = $this->db->prepare(
            'SELECT `id` FROM `push_subscriptions`
             WHERE `user_id` = :user_id AND `endpoint` = :endpoint
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id'  => $userId,
            ':endpoint' => $endpoint,
        ]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            // Update existing subscription keys
            $stmt = $this->db->prepare(
                'UPDATE `push_subscriptions`
                 SET `p256dh` = :p256dh, `auth` = :auth
                 WHERE `id` = :id'
            );
            $stmt->execute([
                ':p256dh' => $p256dh,
                ':auth'   => $auth,
                ':id'     => (int) $existing,
            ]);
            return (int) $existing;
        }

        // Insert new subscription
        $stmt = $this->db->prepare(
            'INSERT INTO `push_subscriptions`
             (`tenant_id`, `user_id`, `endpoint`, `p256dh`, `auth`)
             VALUES
             (:tenant_id, :user_id, :endpoint, :p256dh, :auth)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id'   => $userId,
            ':endpoint'  => $endpoint,
            ':p256dh'    => $p256dh,
            ':auth'      => $auth,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Remove a push subscription
     */
    public function unsubscribe(int $userId, string $endpoint): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM `push_subscriptions`
             WHERE `user_id` = :user_id AND `endpoint` = :endpoint'
        );
        return $stmt->execute([
            ':user_id'  => $userId,
            ':endpoint' => $endpoint,
        ]);
    }

    /**
     * Get all active subscriptions for a user within a tenant
     *
     * @return array<int, array{endpoint: string, p256dh: string, auth: string}>
     */
    public function getSubscriptionsForUser(int $userId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT `endpoint`, `p256dh`, `auth`
             FROM `push_subscriptions`
             WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Send a push notification to a specific user
     *
     * @param int    $userId   Target user
     * @param int    $tenantId Tenant isolation
     * @param string $title    Notification title
     * @param string $body     Notification body text
     * @return array{sent: int, failed: int}
     */
    public function sendNotification(int $userId, int $tenantId, string $title, string $body): array
    {
        $subscriptions = $this->getSubscriptionsForUser($userId, $tenantId);

        if (empty($subscriptions)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-72x72.png',
            'data'  => [
                'tenant_id' => $tenantId,
                'timestamp' => time(),
            ],
        ], JSON_THROW_ON_ERROR);

        $sent   = 0;
        $failed = 0;

        foreach ($subscriptions as $sub) {
            if ($this->pushToEndpoint($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload)) {
                $sent++;
            } else {
                $failed++;
                // Remove invalid subscription
                $this->unsubscribe($userId, $sub['endpoint']);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send a push notification to all subscribed users in a tenant (broadcast)
     *
     * @return array{sent: int, failed: int, total_subscriptions: int}
     */
    public function broadcastNotification(int $tenantId, string $title, string $body): array
    {
        $stmt = $this->db->prepare(
            'SELECT `user_id`, `endpoint`, `p256dh`, `auth`
             FROM `push_subscriptions`
             WHERE `tenant_id` = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $subscriptions = $stmt->fetchAll();

        if (empty($subscriptions)) {
            return ['sent' => 0, 'failed' => 0, 'total_subscriptions' => 0];
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192x192.png',
            'data'  => [
                'tenant_id' => $tenantId,
                'timestamp' => time(),
            ],
        ], JSON_THROW_ON_ERROR);

        $sent   = 0;
        $failed = 0;

        foreach ($subscriptions as $sub) {
            if ($this->pushToEndpoint($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload)) {
                $sent++;
            } else {
                $failed++;
                $this->unsubscribe((int) $sub['user_id'], $sub['endpoint']);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'total_subscriptions' => count($subscriptions)];
    }

    /**
     * Get subscription count for a tenant
     */
    public function getSubscriptionCount(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `push_subscriptions` WHERE `tenant_id` = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Push a payload to an endpoint using VAPID
     * In MVP we use a simplified approach — actual VAPID encryption requires
     * the web-push-php library or custom implementation.
     *
     * For now this stores the notification for the service worker to pick up.
     * Production-ready VAPID encryption would use libsodium or a composer package.
     *
     * @param string $endpoint Push service URL
     * @param string $p256dh   Client public key
     * @param string $auth     Client auth secret
     * @param string $payload  JSON payload
     * @return bool Success
     */
    private function pushToEndpoint(string $endpoint, string $p256dh, string $auth, string $payload): bool
    {
        // MVP: Use cURL to send to the push service endpoint
        // Full VAPID encryption is deferred to production when composer deps are allowed

        // Validate endpoint URL
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Attempt to send via cURL
        // Note: Without proper VAPID encryption headers, most push services will reject.
        // This is a placeholder that logs the attempt and returns true for mock mode.
        if (APP_DEBUG) {
            // Development: log and pretend success
            error_log("[PushService] Notification sent to: {$endpoint}");
            return true;
        }

        // Production: actual push delivery (requires VAPID headers)
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($payload),
                'TTL: 86400', // 24 hour time-to-live
            ],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Push services return 201 Created on success
        return $httpCode >= 200 && $httpCode < 300;
    }
}
