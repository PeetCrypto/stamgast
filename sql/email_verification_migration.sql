-- ============================================================
-- Email Verification Migration
-- Date: 2026-05-24
-- Description: Adds email verification code and verified_at
--   columns to users table for email-based verification flow.
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `email_verification_code` VARCHAR(8) NULL DEFAULT NULL
        COMMENT '8-char uppercase code sent to user email after registration'
        AFTER `suspended_by`,
    ADD COLUMN `email_verified_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When the user verified their email address'
        AFTER `email_verification_code`;

-- Index for fast code lookups
ALTER TABLE `users` ADD INDEX `idx_email_verification_code` (`email_verification_code`);
