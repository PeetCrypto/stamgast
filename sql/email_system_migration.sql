-- ==========================================================================
-- REGULR.vip EMAIL SYSTEM MIGRATION
-- MySQL 8.0+ | UTF-8MB4
-- Run via: migrate_email.php (NOT directly in phpMyAdmin due to HTML content)
-- ==========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- 1. EMAIL_CONFIG (SMTP Provider Settings)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_config` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `provider`          ENUM('brevo','sender_net','aws_ses') NOT NULL DEFAULT 'brevo',
    `smtp_host`         VARCHAR(255)    NOT NULL,
    `smtp_port`         INT             NOT NULL DEFAULT 587,
    `smtp_encryption`   ENUM('tls','ssl','starttls','none') NOT NULL DEFAULT 'tls',
    `smtp_user`         TEXT            NOT NULL COMMENT 'AES-256-CBC encrypted',
    `smtp_pass`         TEXT            NOT NULL COMMENT 'AES-256-CBC encrypted',
    `from_email`        VARCHAR(255)    NOT NULL DEFAULT 'no-reply@regulr.vip',
    `from_name`         VARCHAR(255)    NOT NULL DEFAULT 'REGULR.vip',
    `is_active`         BOOLEAN         NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 2. EMAIL_TEMPLATES (Template Management per Tenant)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `type`              ENUM('tenant_welcome','admin_invite','guest_confirmation','guest_password_reset','marketing') NOT NULL,
    `subject`           VARCHAR(500)    NOT NULL,
    `content`           MEDIUMTEXT      NOT NULL COMMENT 'HTML template with {{placeholders}}',
    `text_content`      TEXT            NULL COMMENT 'Plain-text fallback',
    `tenant_id`         INT             NULL COMMENT 'NULL = global default template',
    `language_code`     VARCHAR(10)     NOT NULL DEFAULT 'nl',
    `is_default`        BOOLEAN         NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email_tpl_type` (`type`),
    INDEX `idx_email_tpl_tenant` (`tenant_id`),
    CONSTRAINT `fk_email_tpl_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 3. EMAIL_LOG (Sent Email Tracking)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NULL,
    `user_id`           INT             NULL,
    `recipient_email`   VARCHAR(255)    NOT NULL,
    `subject`           VARCHAR(500)    NOT NULL,
    `template_type`     VARCHAR(100)    NULL,
    `template_id`       INT             NULL,
    `provider`          VARCHAR(50)     NOT NULL,
    `status`            ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `error_message`     TEXT            NULL,
    `sent_at`           TIMESTAMP       NULL,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_elog_tenant` (`tenant_id`),
    INDEX `idx_elog_status` (`status`),
    INDEX `idx_elog_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================================
-- NOTE: Default templates are inserted by migrate_email.php using prepared
-- statements. This avoids semicolon-in-HTML issues that break SQL file parsing.
-- ==========================================================================
