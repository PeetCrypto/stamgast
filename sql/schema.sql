-- ==========================================================================
-- STAMGAST LOYALTY PLATFORM - COMPLETE DATABASE SCHEMA
-- MySQL 8.0+ | UTF-8MB4 | Multi-Tenant Isolated
-- ==========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- 1. TENANTS (Establishment Management)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `uuid`              VARCHAR(36)     NOT NULL,
    `name`              VARCHAR(255)    NOT NULL,
    `slug`              VARCHAR(100)    NOT NULL,
    `brand_color`       VARCHAR(7)      DEFAULT '#FFC107',
    `secondary_color`   VARCHAR(7)      DEFAULT '#FF9800',
    `logo_path`         VARCHAR(255)    NULL,
    `secret_key`        VARCHAR(64)     NOT NULL COMMENT 'HMAC-SHA256 secret: bin2hex(random_bytes(32))',
    `mollie_api_key`    VARCHAR(255)    NULL,
    `mollie_status`     ENUM('mock','test','live') DEFAULT 'mock',
    `whitelisted_ips`   TEXT            NULL COMMENT 'Line-separated whitelist IPs',
    `contact_name`      VARCHAR(255)    NULL COMMENT 'NAW: Contactpersoon naam',
    `contact_email`     VARCHAR(255)    NULL COMMENT 'NAW: Contactpersoon e-mail',
    `phone`             VARCHAR(50)     NULL COMMENT 'NAW: Telefoonnummer',
    `address`           VARCHAR(255)    NULL COMMENT 'NAW: Straat + huisnummer',
    `postal_code`       VARCHAR(20)     NULL COMMENT 'NAW: Postcode',
    `city`              VARCHAR(100)    NULL COMMENT 'NAW: Plaats',
    `country`           VARCHAR(100)    DEFAULT 'Nederland' COMMENT 'NAW: Land',
    `is_active`         BOOLEAN         DEFAULT 1 COMMENT '0 = tenant uitgeschakeld door superadmin',
    `feature_push`      BOOLEAN         DEFAULT 1,
    `feature_marketing` BOOLEAN         DEFAULT 1,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenants_uuid` (`uuid`),
    UNIQUE KEY `uk_tenants_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 2. USERS (Identity & Role Management)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NULL COMMENT 'NULL for platform-level superadmins',
    `email`             VARCHAR(255)    NOT NULL,
    `password_hash`     VARCHAR(255)    NOT NULL,
    `role`              ENUM('superadmin','admin','bartender','guest') NOT NULL,
    `first_name`        VARCHAR(100)    NOT NULL,
    `last_name`         VARCHAR(100)    NOT NULL,
    `birthdate`         DATE            NULL,
    `photo_url`         VARCHAR(255)    NULL,
    `photo_status`      ENUM('unvalidated','validated','blocked') DEFAULT 'unvalidated',
    `push_token`        TEXT            NULL,
    `last_activity`     TIMESTAMP       NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_tenant_email` (`tenant_id`, `email`),
    INDEX `idx_users_tenant` (`tenant_id`),
    INDEX `idx_users_role` (`role`),
    CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 3. WALLETS (Financial)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
    `user_id`           INT             NOT NULL,
    `tenant_id`         INT             NOT NULL,
    `balance_cents`     BIGINT          DEFAULT 0 COMMENT 'Balance in cents (1.00 = 100)',
    `points_cents`      BIGINT          DEFAULT 0 COMMENT 'Loyalty points (1:1 = cents)',
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    INDEX `idx_wallets_tenant` (`tenant_id`),
    CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wallets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 4. LOYALTY_TIERS (Tier Configuration)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loyalty_tiers` (
    `id`                    INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT             NOT NULL,
    `name`                  VARCHAR(100)    NOT NULL,
    `min_deposit_cents`     BIGINT          DEFAULT 0 COMMENT 'Threshold to reach this tier',
    `topup_amount_cents`    INT             NOT NULL DEFAULT 10000 COMMENT 'Fixed top-up amount for this package (min 10000 = EUR100)',
    `alcohol_discount_perc` DECIMAL(5,2)    DEFAULT 0.00 COMMENT 'Max 25% (legal limit)',
    `food_discount_perc`    DECIMAL(5,2)    DEFAULT 0.00 COMMENT 'Max 100%',
    `points_multiplier`     DECIMAL(3,2)    DEFAULT 1.00 COMMENT 'Points multiplier',
    `is_active`             TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = package disabled by admin, 1 = active',
    `sort_order`            INT             NOT NULL DEFAULT 0 COMMENT 'Display order, lower = first',
    `created_at`            TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tiers_tenant` (`tenant_id`),
    CONSTRAINT `fk_tiers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 5. TRANSACTIONS (Transaction Ledger)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
    `id`                  INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT             NULL COMMENT 'NULL for platform-level superadmin actions',
    `user_id`             INT             NOT NULL,
    `bartender_id`        INT             NULL COMMENT 'NULL for deposits',
    `type`                ENUM('payment','deposit','bonus','correction') NOT NULL,
    `amount_alc_cents`    INT             DEFAULT 0,
    `amount_food_cents`   INT             DEFAULT 0,
    `discount_alc_cents`  INT             DEFAULT 0,
    `discount_food_cents` INT             DEFAULT 0,
    `final_total_cents`   INT             NOT NULL COMMENT 'Final amount after discount',
    `points_earned`       INT             DEFAULT 0,
    `points_used`         INT             DEFAULT 0,
    `ip_address`          VARCHAR(45)     NOT NULL,
    `device_fingerprint`  VARCHAR(255)    NULL,
    `mollie_payment_id`   VARCHAR(255)    NULL COMMENT 'Mollie transaction ID (deposits only)',
    `description`         VARCHAR(500)    NULL,
    `created_at`          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_trans_tenant` (`tenant_id`),
    INDEX `idx_trans_user` (`user_id`),
    INDEX `idx_trans_bartender` (`bartender_id`),
    INDEX `idx_trans_type` (`type`),
    INDEX `idx_trans_created` (`created_at`),
    CONSTRAINT `fk_trans_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_trans_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_trans_bartender` FOREIGN KEY (`bartender_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 6. PUSH_SUBSCRIPTIONS (Web Push)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NOT NULL,
    `user_id`           INT             NOT NULL,
    `endpoint`          TEXT            NOT NULL,
    `p256dh`            TEXT            NOT NULL,
    `auth`              TEXT            NOT NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_push_tenant` (`tenant_id`),
    INDEX `idx_push_user` (`user_id`),
    CONSTRAINT `fk_push_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 7. EMAIL_QUEUE (Marketing)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NOT NULL,
    `user_id`           INT             NOT NULL,
    `subject`           VARCHAR(255)    NOT NULL,
    `body_html`         TEXT            NOT NULL,
    `status`            ENUM('pending','sent','failed') DEFAULT 'pending',
    `sent_at`           TIMESTAMP       NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email_tenant` (`tenant_id`),
    INDEX `idx_email_status` (`status`),
    CONSTRAINT `fk_email_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 8. AUDIT_LOG (Audit Trail)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NULL COMMENT 'NULL for platform-level superadmin actions',
    `user_id`           INT             NULL,
    `action`            VARCHAR(100)    NOT NULL COMMENT 'e.g. payment.processed',
    `entity_type`       VARCHAR(50)     NULL COMMENT 'e.g. transaction',
    `entity_id`         INT             NULL,
    `ip_address`        VARCHAR(45)     NOT NULL,
    `user_agent`        TEXT            NULL,
    `metadata`          JSON            NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_tenant` (`tenant_id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
