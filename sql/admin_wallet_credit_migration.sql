-- =========================================================================
-- Admin Manual Wallet Credit Migration
-- Adds: performed_by + admin_reason columns on transactions
-- Adds: wallet_credit_log table for detailed audit trail
-- =========================================================================

-- 1. Add performed_by column to transactions (who performed the manual credit)
ALTER TABLE `transactions`
    ADD COLUMN `performed_by` INT NULL COMMENT 'Admin user ID who performed manual credit/debit' AFTER `bartender_id`,
    ADD COLUMN `admin_reason` VARCHAR(500) NULL COMMENT 'Reason/note for manual wallet correction' AFTER `description`;

-- Index for fast lookup of admin-performed corrections
ALTER TABLE `transactions`
    ADD INDEX `idx_trans_performed_by` (`performed_by`);

-- FK constraint for performed_by
ALTER TABLE `transactions`
    ADD CONSTRAINT `fk_trans_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 2. Detailed audit log for every wallet credit (immutable trail)
CREATE TABLE IF NOT EXISTS `wallet_credit_log` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT             NOT NULL,
    `guest_user_id`     INT             NOT NULL COMMENT 'The guest whose wallet was credited',
    `admin_user_id`     INT             NOT NULL COMMENT 'The admin who performed the credit',
    `transaction_id`    INT             NULL COMMENT 'FK to the correction transaction record',
    `amount_cents`      INT             NOT NULL COMMENT 'Amount credited in cents (always positive)',
    `balance_before`    INT             NOT NULL COMMENT 'Wallet balance before credit (cents)',
    `balance_after`     INT             NOT NULL COMMENT 'Wallet balance after credit (cents)',
    `reason`            VARCHAR(500)    NOT NULL COMMENT 'Required reason/note from admin',
    `ip_address`        VARCHAR(45)     NOT NULL,
    `user_agent`        TEXT            NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_wcl_tenant` (`tenant_id`),
    INDEX `idx_wcl_guest` (`guest_user_id`),
    INDEX `idx_wcl_admin` (`admin_user_id`),
    INDEX `idx_wcl_transaction` (`transaction_id`),
    INDEX `idx_wcl_created` (`created_at`),
    CONSTRAINT `fk_wcl_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wcl_guest` FOREIGN KEY (`guest_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wcl_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wcl_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
