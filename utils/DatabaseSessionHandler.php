<?php
declare(strict_types=1);

/**
 * Database-Backed PHP Session Handler
 * ==========================================================================
 * WHY: PHP's default sessions are stored as files in the system tmp dir.
 *      On shared hosting (Hostinger) and during PHP-FPM restarts / deploys,
 *      those files get wiped, logging every user out instantly.
 *
 *      This handler stores sessions in the `sessions` MySQL table instead.
 *      - Sessions survive restarts, deploys and tmp cleanup.
 *      - Business DB migrations never touch the `sessions` table, so running a
 *        migration no longer logs anyone out.
 *
 * SECURITY:
 *      - The raw PHP session ID is NEVER stored. We store its SHA-256 hash, so a
 *        database dump cannot be used directly for session hijacking.
 *      - Concurrent writes (multiple tabs / concurrent requests on the same
 *        session) are serialised with a MySQL named lock (GET_LOCK) to prevent
 *        session corruption. Named locks are connection-scoped and auto-released
 *        when the request ends, so a crash can never leave a permanent lock.
 *
 * RESILIENCE:
 *      - If the database is unreachable (e.g. before the first migration runs),
 *        registration falls back to PHP's default file handler so the app still
 *        works. Per-call methods also fail safe and let PHP continue.
 *
 * WIRING:
 *      Registered once in index.php (and web_migrate.php) BEFORE the first
 *      session_start(). Every session_start() in the same request then uses
 *      this handler automatically.
 */

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $db;
    private bool $locked   = false;
    private string $lockId = '';

    /** Lock name prefix + total length must stay within MySQL's 64-char limit. */
    private const LOCK_PREFIX = 'regulr_sess_';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Register this handler as PHP's session save handler.
     * Falls back to the default (file) handler if the DB connection fails.
     */
    public static function register(): void
    {
        // Idempotent guard: if a session is already active, the save handler
        // and session ini settings can no longer be changed. This happens when
        // register() is invoked twice in the same request (e.g. web_migrate.php
        // is included by index.php, which already registered the handler and
        // started the session). Silently no-op instead of emitting warnings.
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // CRITICAL: Align PHP's GC lifetime with our app's longest session
        // lifetime (guests = 5 years). On shared hosting, session.gc_maxlifetime
        // is often just 24 minutes — without this override, PHP's garbage
        // collector would call our gc() with that short value and wipe every
        // guest session, logging everyone out (the exact bug we are fixing).
        if (defined('SESSION_TIMEOUT_GUEST')) {
            ini_set('session.gc_maxlifetime', (string) SESSION_TIMEOUT_GUEST);
        }

        try {
            $db = Database::getInstance()->getConnection();

            // Self-bootstrap: ensure the storage table exists BEFORE we wire up
            // the handler. Without this there is a chicken-and-egg deadlock —
            // the table is created by a migration, but the migration page itself
            // triggers a session write that fails because the table is missing.
            // CREATE TABLE IF NOT EXISTS is idempotent and mirrors the migration.
            self::ensureTableExists($db);

            $handler = new self($db);
            // The second arg = true tells PHP to register a shutdown handler
            // that calls write() + close(), mirroring the default behaviour.
            session_set_save_handler($handler, true);
        } catch (\Throwable $e) {
            // DB unavailable — keep using the default file-based handler.
            // Logged but non-fatal so the app degrades gracefully.
            error_log('DatabaseSessionHandler: DB unavailable, using file sessions: ' . $e->getMessage());
        }
    }

    /**
     * Create the sessions table if it does not yet exist.
     * Kept in sync with sql/session_storage_migration.sql.
     */
    private static function ensureTableExists(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `sessions` (
                `id`            VARCHAR(64)     NOT NULL,
                `data`          MEDIUMTEXT      NOT NULL,
                `last_activity` INT UNSIGNED    NOT NULL,
                `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_sessions_last_activity` (`last_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // SessionHandlerInterface
    // ─────────────────────────────────────────────────────────────────────

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    /**
     * Read session data. Acquires a named lock so concurrent requests for the
     * same session are serialised (prevents last-write-wins corruption).
     */
    public function read(string $id): string|false
    {
        try {
            $this->acquireLock($id);

            $stmt = $this->db->prepare('SELECT `data` FROM `sessions` WHERE `id` = :id LIMIT 1');
            $stmt->execute([':id' => $this->hashId($id)]);
            $data = $stmt->fetchColumn();

            // PHP expects '' for empty/new sessions; never return null.
            return $data !== false ? (string) $data : '';
        } catch (\Throwable $e) {
            error_log('DatabaseSessionHandler::read failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Write (create or update) the session row. Atomic via UPSERT.
     */
    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO `sessions` (`id`, `data`, `last_activity`)
                 VALUES (:id, :data, :ts)
                 ON DUPLICATE KEY UPDATE
                    `data`          = VALUES(`data`),
                    `last_activity` = VALUES(`last_activity`)'
            );
            $stmt->execute([
                ':id'   => $this->hashId($id),
                ':data' => $data,
                ':ts'   => time(),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('DatabaseSessionHandler::write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM `sessions` WHERE `id` = :id');
            $stmt->execute([':id' => $this->hashId($id)]);
            return true;
        } catch (\Throwable $e) {
            error_log('DatabaseSessionHandler::destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Garbage collection: delete sessions older than maxlifetime.
     * Triggered by PHP based on session.gc_probability / gc_divisor.
     *
     * CRITICAL: We NEVER use a cutoff shorter than SESSION_TIMEOUT_GUEST,
     * regardless of what PHP passes in. PHP invokes gc() with
     * session.gc_maxlifetime, which on shared hosting can be as low as 24
     * minutes — using that verbatim would purge every active guest session.
     */
    public function gc(int $max_lifetime): int|false
    {
        $cutoffAge = $max_lifetime;
        if (defined('SESSION_TIMEOUT_GUEST') && SESSION_TIMEOUT_GUEST > $cutoffAge) {
            $cutoffAge = SESSION_TIMEOUT_GUEST;
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM `sessions` WHERE `last_activity` < :cutoff');
            $stmt->execute([':cutoff' => time() - $cutoffAge]);
            return (int) $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('DatabaseSessionHandler::gc failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Hash the raw PHP session ID before storing/looking it up.
     * SHA-256 keeps the raw ID out of the database (defence in depth).
     */
    private function hashId(string $id): string
    {
        return hash('sha256', $id);
    }

    /**
     * Acquire a MySQL named lock keyed on the (truncated hash of the) session ID.
     * Blocks up to 10s for a concurrent holder to release.
     */
    private function acquireLock(string $id): void
    {
        try {
            $name = $this->lockName($id);
            $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 10)');
            $stmt->execute([':name' => $name]);
            $result = $stmt->fetchColumn();

            // GET_LOCK returns 1 (ok), 0 (timeout), NULL (error).
            if ((int) $result === 1) {
                $this->locked  = true;
                $this->lockId  = $id;
            } else {
                // Could not acquire — proceed without a lock rather than
                // hanging the request. Worst case is a rare last-write-wins.
                $this->locked = false;
            }
        } catch (\Throwable $e) {
            $this->locked = false;
        }
    }

    private function releaseLock(): void
    {
        if (!$this->locked || $this->lockId === '') {
            return;
        }
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute([':name' => $this->lockName($this->lockId)]);
        } catch (\Throwable $e) {
            // Non-fatal: the lock auto-releases when the connection closes.
        }
        $this->locked  = false;
        $this->lockId  = '';
    }

    /**
     * Build a lock name that fits MySQL's 64-char limit.
     * LOCK_PREFIX (12) + first 40 hex chars of the SHA-256 hash = 52 chars.
     */
    private function lockName(string $id): string
    {
        return self::LOCK_PREFIX . substr($this->hashId($id), 0, 40);
    }
}
