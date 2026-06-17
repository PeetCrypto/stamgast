<?php
declare(strict_types=1);

/**
 * Rate Limiter — prevents brute-force login attacks.
 *
 * Uses the existing audit_log table to count recent failed login attempts.
 * No new database table required — works on shared hosting out of the box.
 *
 * Limits:
 * - Per IP:     max 10 failed attempts per 15 minutes
 * - Per email:  max 5 failed attempts per 15 minutes
 */
class RateLimiter
{
    private PDO $db;

    /** Max failed attempts per IP per window */
    public const IP_MAX_ATTEMPTS = 10;

    /** Max failed attempts per email per window */
    public const EMAIL_MAX_ATTEMPTS = 5;

    /** Time window in seconds (15 minutes) */
    public const WINDOW_SECONDS = 900;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if the given IP has exceeded the rate limit.
     *
     * @param string $ip Client IP address
     * @return bool True if rate limited (should block)
     */
    public function isIpRateLimited(string $ip): bool
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return false;
        }

        $count = $this->countRecentFailures('ip', $ip);
        return $count >= self::IP_MAX_ATTEMPTS;
    }

    /**
     * Check if the given email has exceeded the rate limit.
     *
     * @param string $email Email address (lowercased)
     * @return bool True if rate limited (should block)
     */
    public function isEmailRateLimited(string $email): bool
    {
        if (empty($email)) {
            return false;
        }

        $count = $this->countRecentFailures('email', strtolower($email));
        return $count >= self::EMAIL_MAX_ATTEMPTS;
    }

    /**
     * Get remaining attempts for an IP before rate limiting kicks in.
     */
    public function getRemainingIpAttempts(string $ip): int
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return self::IP_MAX_ATTEMPTS;
        }
        $count = $this->countRecentFailures('ip', $ip);
        return max(0, self::IP_MAX_ATTEMPTS - $count);
    }

    /**
     * Count recent failed login attempts from the audit_log.
     *
     * @param string $type  'ip' or 'email'
     * @param string $value IP address or email (lowercased)
     * @return int Number of failed attempts in the time window
     */
    private function countRecentFailures(string $type, string $value): int
    {
        try {
            if ($type === 'ip') {
                $sql = "SELECT COUNT(*) FROM `audit_log`
                        WHERE `action` IN ('auth.login_failed', 'auth.login_failed_no_tenant')
                        AND `ip_address` = :value
                        AND `created_at` >= (NOW() - INTERVAL :window SECOND)";
            } else {
                // For email: search in metadata JSON
                $sql = "SELECT COUNT(*) FROM `audit_log`
                        WHERE `action` IN ('auth.login_failed', 'auth.login_failed_no_tenant')
                        AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.email'))) = :value
                        AND `created_at` >= (NOW() - INTERVAL :window SECOND)";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':value', $value);
            $stmt->bindValue(':window', self::WINDOW_SECONDS, PDO::PARAM_INT);
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // If audit_log table doesn't exist or query fails, don't block
            error_log('[RateLimiter] Failed to count failures: ' . $e->getMessage());
            return 0;
        }
    }
}
