<?php
declare(strict_types=1);

/**
 * Marketing Service
 * Handles user segmentation and email queue management
 */

require_once __DIR__ . '/Email/EmailService.php';

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
     * Get email queue status counts + paginated recent items
     *
     * @param int    $tenantId     Tenant isolation
     * @param int    $page         Page number (1-based)
     * @param int    $perPage      Items per page
     * @param string $statusFilter Filter by status ('', 'pending', 'sent', 'failed')
     * @return array{pending: int, sent: int, failed: int, total: int, items: array, pagination: array}
     */
    public function getQueueStatus(int $tenantId, int $page = 1, int $perPage = 20, string $statusFilter = ''): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;

        // Counts by status (always all statuses for the stat cards)
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

        // Paginated items query
        $whereClause = 'WHERE eq.`tenant_id` = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if (in_array($statusFilter, ['pending', 'sent', 'failed'], true)) {
            $whereClause .= ' AND eq.`status` = :status_filter';
            $params[':status_filter'] = $statusFilter;
        }

        // Count total matching items for pagination
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM `email_queue` eq {$whereClause}"
        );
        $countStmt->execute($params);
        $totalItems = (int) $countStmt->fetch()['total'];
        $totalPages = (int) ceil($totalItems / $perPage);

        // Fetch paginated items
        $itemStmt = $this->db->prepare(
            "SELECT eq.`id`, eq.`subject`, eq.`status`, eq.`created_at`, eq.`sent_at`,
                    u.`email`, u.`first_name`, u.`last_name`
             FROM `email_queue` eq
             JOIN `users` u ON u.`id` = eq.`user_id`
             {$whereClause}
             ORDER BY eq.`created_at` DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $val) {
            $itemStmt->bindValue($key, $val, PDO::PARAM_STR);
        }
        $itemStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $itemStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $itemStmt->execute();
        $counts['items'] = $itemStmt->fetchAll();
        $counts['pagination'] = [
            'page'       => $page,
            'per_page'   => $perPage,
            'total_items'=> $totalItems,
            'total_pages'=> $totalPages,
        ];

        return $counts;
    }

    /**
     * Process pending emails in the queue
     * Sends up to $batchSize emails per run using EmailService
     *
     * @param int $tenantId Tenant isolation (0 = all tenants)
     * @param int $batchSize Max emails to process
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processQueue(int $tenantId = 0, int $batchSize = 50): array
    {
        // Fetch pending emails with user data for placeholder replacement
        $sql = "SELECT eq.`id`, eq.`tenant_id`, eq.`user_id`, eq.`subject`, eq.`body_html`,
                       u.`email`, u.`first_name`, u.`last_name`,
                       w.`balance_cents`,
                       t.`name` AS tenant_name
                FROM `email_queue` eq
                JOIN `users` u ON u.`id` = eq.`user_id`
                LEFT JOIN `wallets` w ON w.`user_id` = u.`id`
                LEFT JOIN `tenants` t ON t.`id` = eq.`tenant_id`
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

            // Build placeholder variables for this recipient
            $balanceEur = number_format(($email['balance_cents'] ?? 0) / 100, 2, ',', '.');
            $tierName = $this->getUserTierName((int) $email['tenant_id'], (int) $email['user_id']);

            $placeholders = [
                '{{first_name}}'  => $email['first_name'] ?? '',
                '{{last_name}}'   => $email['last_name'] ?? '',
                '{{tenant_name}}' => $email['tenant_name'] ?? APP_NAME,
                '{{balance}}'     => '€' . $balanceEur,
                '{{tier}}'        => $tierName,
            ];

            // Replace placeholders in subject and body
            $subject  = str_replace(array_keys($placeholders), array_values($placeholders), $email['subject']);
            $bodyHtml = str_replace(array_keys($placeholders), array_values($placeholders), $email['body_html']);

            // Generate plain text fallback from HTML
            $textContent = $this->htmlToPlainText($bodyHtml);

            $success = $this->sendEmail(
                $email['email'],
                $subject,
                $bodyHtml,
                $textContent,
                (int) $email['tenant_id'],
                (int) $email['user_id'],
                $email['tenant_name'] ?? ''
            );

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
     * Send an email using EmailService (SMTP providers) with fallback
     *
     * @param string $to         Recipient email
     * @param string $subject    Email subject
     * @param string $bodyHtml   HTML body
     * @param string $textContent Plain text fallback
     * @param int    $tenantId   Tenant ID for logging
     * @param int    $userId     User ID for logging
     * @return bool Success
     */
    private function sendEmail(
        string $to,
        string $subject,
        string $bodyHtml,
        string $textContent,
        int $tenantId,
        int $userId,
        string $tenantName = ''
    ): bool {
        try {
            $emailService = new EmailService($this->db);
            $result = $emailService->sendEmail(
                $to,
                $subject,
                $bodyHtml,
                $textContent,
                'marketing',
                $tenantId,
                $userId,
                $tenantName ?: null
            );

            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log("[MarketingService] Email to: {$to}, Subject: {$subject}, From: {$tenantName}, Result: " . ($result ? 'SENT' : 'FAILED'));
            }

            return $result;
        } catch (\Throwable $e) {
            error_log("[MarketingService] EmailService failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the tier name for a user based on their total deposits
     */
    private function getUserTierName(int $tenantId, int $userId): string
    {
        try {
            // Calculate total deposits for this user
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(`final_total_cents`), 0) AS total
                 FROM `transactions`
                 WHERE `user_id` = :user_id AND `tenant_id` = :tenant_id AND `type` = 'deposit'"
            );
            $stmt->execute([':user_id' => $userId, ':tenant_id' => $tenantId]);
            $row = $stmt->fetch();
            $totalDeposits = (int) ($row['total'] ?? 0);

            // Find matching tier
            $tiers = (new LoyaltyTier($this->db))->getByTenant($tenantId);
            $matchedTier = '';
            foreach ($tiers as $tier) {
                if ($totalDeposits >= (int) $tier['min_deposit_cents']) {
                    $matchedTier = $tier['name'];
                }
            }
            return $matchedTier;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Convert HTML to plain text (simple strip-tags with whitespace cleanup)
     */
    private function htmlToPlainText(string $html): string
    {
        $text = strip_tags($html);
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        return trim($text);
    }
}
