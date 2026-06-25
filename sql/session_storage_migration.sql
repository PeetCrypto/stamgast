-- ==========================================================================
-- REGULR.vip — Database-Backed Session Storage
-- ==========================================================================
-- Store PHP sessions in MySQL instead of volatile files on disk.
--
-- WHY: On shared hosting (Hostinger) and during PHP-FPM restarts / deploys,
--      session files in the system tmp dir get wiped, logging every user out.
--      This table persists across restarts and is never touched by business
--      migrations, so users stay logged in.
--
-- The session ID is stored as a SHA-256 hash (never the raw ID) so a database
-- dump cannot be used directly for session hijacking.
--
-- Idempotent: uses CREATE TABLE IF NOT EXISTS.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `sessions` (
    `id`            VARCHAR(64)     NOT NULL                COMMENT 'SHA-256 hash of the PHP session ID',
    `data`          MEDIUMTEXT      NOT NULL                COMMENT 'Serialized PHP session payload',
    `last_activity` INT UNSIGNED    NOT NULL                COMMENT 'Unix timestamp of last write (for GC)',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PHP session storage (DatabaseSessionHandler)';
