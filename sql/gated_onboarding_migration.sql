-- ============================================================
-- Gated Onboarding Migration (KYC-light)
-- Date: 2026-04-29
-- Description: Adds account_status system for guest verification
-- ============================================================

-- ============================================================
-- 1. ALTER TABLE `users` — Account status & verification fields
-- ============================================================
ALTER TABLE `users`
    ADD COLUMN `account_status` ENUM('unverified','active','suspended') NOT NULL DEFAULT 'unverified'
        COMMENT 'unverified = kan inloggen, active = kan storten/betalen, suspended = geblokkeerd'
        AFTER `photo_status`,
    ADD COLUMN `verified_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Wanneer is de identiteit geverifieerd door barman'
        AFTER `account_status`,
    ADD COLUMN `verified_by` INT NULL DEFAULT NULL
        COMMENT 'FK naar users.id — welke barman/admin heeft de verificatie uitgevoerd'
        AFTER `verified_at`,
    ADD COLUMN `verified_birthdate` DATE NULL DEFAULT NULL
        COMMENT 'Geboortedatum zoals ingevoerd door barman vanaf ID'
        AFTER `verified_by`,
    ADD COLUMN `suspended_reason` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Reden van opschorting'
        AFTER `verified_birthdate`,
    ADD COLUMN `suspended_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Wanneer opgeschort'
        AFTER `suspended_reason`,
    ADD COLUMN `suspended_by` INT NULL DEFAULT NULL
        COMMENT 'FK naar users.id — wie heeft opgeschort'
        AFTER `suspended_at`;

-- Backward compatibility: existing guests with balance > 0 must stay active
UPDATE `users` u
    INNER JOIN `wallets` w ON w.user_id = u.id
SET u.account_status = 'active'
WHERE u.role = 'guest'
  AND w.balance_cents > 0;

-- Index for fast status lookups
ALTER TABLE `users` ADD INDEX `idx_account_status` (`account_status`);
ALTER TABLE `users` ADD INDEX `idx_verified_by` (`verified_by`);

-- ============================================================
-- 2. ALTER TABLE `tenants` — Configurable verification rate limits
-- ============================================================
ALTER TABLE `tenants`
    ADD COLUMN `verification_soft_limit` INT NOT NULL DEFAULT 15
        COMMENT 'Waarschuwing per barman per uur'
        AFTER `whitelisted_ips`,
    ADD COLUMN `verification_hard_limit` INT NOT NULL DEFAULT 30
        COMMENT 'Absolute blokkade per barman per uur'
        AFTER `verification_soft_limit`,
    ADD COLUMN `verification_cooldown_sec` INT NOT NULL DEFAULT 180
        COMMENT 'Seconden wachttijd na mismatch per gast'
        AFTER `verification_hard_limit`,
    ADD COLUMN `verification_max_attempts` INT NOT NULL DEFAULT 2
        COMMENT 'Max verificatiepogingen per gast per 24u'
        AFTER `verification_cooldown_sec`;

-- ============================================================
-- 3. CREATE TABLE `verification_attempts` — Audit trail
-- ============================================================
CREATE TABLE IF NOT EXISTS `verification_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `user_id` INT NOT NULL
        COMMENT 'De gast die geverifieerd wordt',
    `verified_by` INT NOT NULL
        COMMENT 'De barman/admin die controleert',
    `birthdate_seen` DATE NOT NULL
        COMMENT 'Geboortedatum die de barman intypt vanaf ID',
    `birthdate_match` TINYINT(1) NOT NULL
        COMMENT 'Komt het overeen met de registratie?',
    `status_before` ENUM('unverified','active','suspended') NOT NULL
        COMMENT 'Status voor de poging',
    `status_after` ENUM('unverified','active','suspended') NOT NULL
        COMMENT 'Status na de poging',
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `notes` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Optionele notities',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_tenant_id` (`tenant_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_verified_by` (`verified_by`),
    INDEX `idx_created_at` (`created_at`),

    CONSTRAINT `fk_verif_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_verif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_verif_by` FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail voor gated onboarding verificaties';
