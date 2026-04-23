<?php
declare(strict_types=1);

/**
 * Marketing Service
 * Handles user segmentation and email queue management
 */

class MarketingService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Segment users based on criteria
     *
     * @param int   $tenantId Tenant isolation
     * @param array $criteria Filter criteria:
     *   - last_activity_days (int): users active within N days
     *   - min_balance (int): minimum wallet balance in cents
     *   - tier_name (string): loyalty tier name filter
     * @return array{users: array, count: int}
     */
    public function segmentUsers(int $tenantId, array $criteria): array
    {
        $sql = 'SELECT u.`id`, u.`email`, u.`first_name`, u.`last_name`,
                       u.`last_activity`, u.`created_at`,
                       w.`balance_cents`, w.`points_cents`
                FROM `users` u
                LEFT JOIN `wallets` w ON w.`user_id` = u.`id`
                WHERE u.`tenant_id` = :tenant_id
                  AND u.`role` = \'guest\'';

        $params = [':tenant_id' => $tenantId];

        // Filter: last activity within N days
        if (isset($criteria['last_activity_days']) && $criteria['last_activity_days'] > 0) {
            $days = (int) $criteria['last_activity_days'];
            $sql .= ' AND u.`last_activity` >= DATE_SUB(NOW(), INTERVAL :activity_days DAY)';
            $params[':activity_days'] = $days;
        }

        // Filter: minimum balance
        if (isset($criteria['min_balance']) && $criteria['min_balance'] > 0) {
            $minBalance = (int) $criteria['min_balance'];
            $sql .= ' AND w.`balance_cents` >= :min_balance';
            $params[':min_balance'] = $minBalance;
        }

        // Filter: tier name (based on total deposits)
        if (!empty($criteria['tier_name'])) {
            $tierName = trim($criteria['tier_name']);
            $tierModel = new LoyaltyTier($this->db);
            $tiers = $tierModel->getByTenant($tenantId);

            $targetTier = null;
            foreach ($tiers as $tier) {
                if (strcasecmp($tier['name'], $tierName) === 0) {
                    $targetTier = $tier;
                    break;
                }
            }

            if ($targetTier !== null) {
                $minDeposit = (int) $targetTier['min_deposit_cents'];
                // Find the next tier's minimum to create an upper bound
                $nextTierMin = PHP_INT_MAX;
                foreach ($tiers as $tier) {
                    $tierMin = (int) $tier['min_deposit_cents'];
                    if ($tierMin > $minDeposit && $tierMin < $nextTierMin) {
                        $nextTierMin = $tierMin;
                    }
                }

                // Subquery: total deposits for user
                $sql .= ' AND (
                    SELECT COALESCE(SUM(t2.`final_total_cents`), 0)
                    FROM `transactions` t2
                    WHERE t2.`user_id` = u.`id`
                      AND t2.`tenant_id` = :tenant_id_2
                      AND t2.`type` = \'deposit\'
                ) >= :tier_min_deposit';

                $params[':tenant_id_2'] = $tenantId;
                $params[':tier_min_deposit'] = $minDeposit;

                if ($nextTierMin < PHP_INT_MAX) {
                    $sql .= ' AND (
                        SELECT COALESCE(SUM(t3.`final_total_cents`), 0)
                        FROM `transactions` t3
                        WHERE t3.`user_id` = u.`id`
                          AND t3.`tenant_id` = :tenant_id_3
                          AND t3.`type` = \'deposit\'
                    ) < :tier_max_deposit';
                    $params[':tenant_id_3'] = $tenantId;
                    $params[':tier_max_deposit'] = $nextTierMin;
                }
            }
        }

        $sql .= ' ORDER BY u.`last_activity` DESC LIMIT 500';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        // Sanitize output
        $result = array_map(function (array $u): array {
            return [
                'id'             => (int) $u['id'],
                'email'          => $u['email'],
                'first_name'     => $u['first_name'],
                'last_name'      => $u['last_name'],
                'balance_cents'  => (int) ($u['balance_cents'] ?? 0),
                'points_cents'   => (int) ($u['points_cents'] ?? 0),
                'last_activity'  => $u['last_activity'],
            ];
        }, $users);

        return ['users' => $result, 'count' => count($result)];
    }

    /**
     * Compose and queue emails for specific users
     *
     * @param int    $tenantId  Tenant isolation
     * @param int    $adminId   The admin composing the email (for audit)
     * @param string $subject   Email subject
     * @param string $bodyHtml  Email body (HTML)
     * @param array  $userIds   Target user IDs
     * @return array{queued: int}
     */
    public function composeEmail(int $tenantId, int $adminId, string $subject, string $bodyHtml, array $userIds): array
    {
        if (empty($userIds)) {
            return ['queued' => 0];
        }

        // Validate all user IDs belong to this tenant
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT `id` FROM `users`
             WHERE `id` IN ({$placeholders})
               AND `tenant_id` = ?
               AND `role` = 'guest'"
        );
        $params = array_map('intval', $userIds);
        $params[] = $tenantId;
        $stmt->execute($params);
        $validUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($validUsers)) {
            return ['queued' => 0];
        }

        // Insert into email_queue
        $insertStmt = $this->db->prepare(
            'INSERT INTO `email_queue`
             (`tenant_id`, `user_id`, `subject`, `body_html`, `status`)
             VALUES
             (:tenant_id, :user_id, :subject, :body_html, \'pending\')'
        );

        $queued = 0;
        foreach ($validUsers as $userId) {
            $insertStmt->execute([
                ':tenant_id' => $tenantId,
                ':user_id'   => (int) $userId,
                ':subject'   => $subject,
                ':body_html' => $bodyHtml,
            ]);
            $queued++;
        }

        // Audit log
        (new Audit($this->db))->log(
            $tenantId,
            $adminId,
            'marketing.email_composed',
            'email_queue',
            null,
            ['queued' => $queued, 'subject' => $subject]
        );

        return ['queued' => $queued];
    }

    /**
     * Get email queue status counts
     *
     * @param int $tenantId Tenant isolation
     * @return array{pending: int, sent: int, failed: int, total: int}
     */
    public function getQueueStatus(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT `status`, COUNT(*) as cnt
             FROM `email_queue`
             WHERE `tenant_id` = :tenant_id
             GROUP BY `status`'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $status = $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['cnt'];
            }
        }

        $counts['total'] = array_sum($counts);
        return $counts;
    }

    /**
     * Process pending emails in the queue (called by cron job)
     * Sends up to $batchSize emails per run
     *
     * @param int $tenantId Tenant isolation (0 = all tenants)
     * @param int $batchSize Max emails to process
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processQueue(int $tenantId = 0, int $batchSize = 50): array
    {
        $sql = "SELECT eq.*, u.`email`
                FROM `email_queue` eq
                JOIN `users` u ON u.`id` = eq.`user_id`
                WHERE eq.`status` = 'pending'";

        $params = [];
        if ($tenantId > 0) {
            $sql .= ' AND eq.`tenant_id` = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        $sql .= ' ORDER BY eq.`created_at` ASC LIMIT :batch_size';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        }
        $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $emails = $stmt->fetchAll();

        $processed = 0;
        $sent = 0;
        $failed = 0;

        $updateStmt = $this->db->prepare(
            "UPDATE `email_queue` SET `status` = :status, `sent_at` = NOW() WHERE `id` = :id"
        );

        foreach ($emails as $email) {
            $processed++;

            // MVP: Mark as sent (actual SMTP delivery deferred to production)
            // In production, use mail() or an SMTP library here
            $success = $this->sendEmail($email['email'], $email['subject'], $email['body_html']);

            $updateStmt->execute([
                ':status' => $success ? 'sent' : 'failed',
                ':id'     => (int) $email['id'],
            ]);

            if ($success) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send an email (MVP: uses PHP mail(), production: use SMTP)
     *
     * @param string $to       Recipient email
     * @param string $subject  Email subject
     * @param string $bodyHtml HTML body
     * @return bool Success
     */
    private function sendEmail(string $to, string $subject, string $bodyHtml): bool
    {
        // In development mode, just log and pretend success
        if (APP_DEBUG) {
            error_log("[MarketingService] Email to: {$to}, Subject: {$subject}");
            return true;
        }

        // Production: use PHP mail() as fallback
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: noreply@stamgast.nl',
            'Reply-To: noreply@stamgast.nl',
            'X-Mailer: STAMGAST-PHP',
        ];

        return mail($to, $subject, $bodyHtml, implode("\r\n", $headers));
    }
}
