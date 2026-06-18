-- ==========================================================================
-- REGULR.vip — Security Hardening Migration
-- ==========================================================================
-- Adds columns for:
-- 1. Password setup tokens (magic links instead of plaintext passwords in emails)
-- 2. Mollie webhook secret token (webhook authentication)
-- ==========================================================================

-- ── 1. Password Setup Token columns on users table ─────────────────────────
-- Allows sending a one-time setup link instead of a plaintext password in
-- invitation emails. The user sets their own password via /setup-password.
--
-- NOTE: We do NOT use "ADD COLUMN IF NOT EXISTS" here because that syntax is
-- only supported by MariaDB and MySQL 8.0.29+. The migration runner
-- (migrate_new.php) already treats "Duplicate column" errors as idempotent
-- success, so plain ADD COLUMN is safe across all MySQL versions.
ALTER TABLE `users`
    ADD COLUMN `password_setup_token_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `password_hash`,
    ADD COLUMN `password_setup_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `password_setup_token_hash`;

-- ── 2. Mollie Webhook Secret (stored in platform_settings) ──────────────────
-- The webhook URL includes ?token=XXX which is validated against this hash.
-- This is inserted via the migration runner (inline), not here, because
-- platform_settings may already exist with data.
