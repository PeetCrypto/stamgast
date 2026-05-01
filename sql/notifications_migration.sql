-- ==========================================================================
-- REGULR.vip - Notifications Table Migration
-- Adds a dedicated notifications table for guest inbox with soft-delete
-- ==========================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------
-- NOTIFICATIONS (Guest Inbox Items)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`              INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT             NOT NULL,
    `user_id`         INT             NOT NULL,
    `transaction_id`  INT             NULL COMMENT 'Linked transaction (nullable for future non-transaction notifications)',
    `type`            VARCHAR(50)     NOT NULL COMMENT 'deposit, payment, bonus, correction, system',
    `icon`            VARCHAR(10)     NOT NULL DEFAULT '📋',
    `title`           VARCHAR(255)    NOT NULL,
    `body`            VARCHAR(500)    NOT NULL,
    `color`           VARCHAR(100)    NOT NULL DEFAULT 'var(--text-secondary)',
    `points_earned`   INT             NOT NULL DEFAULT 0,
    `is_read`         TINYINT(1)      NOT NULL DEFAULT 0,
    `deleted_at`      TIMESTAMP       NULL COMMENT 'Soft-delete: NULL = active, set to timestamp when deleted',
    `created_at`      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notif_tenant` (`tenant_id`),
    INDEX `idx_notif_user` (`user_id`),
    INDEX `idx_notif_user_read` (`user_id`, `is_read`),
    INDEX `idx_notif_deleted` (`deleted_at`),
    INDEX `idx_notif_transaction` (`transaction_id`),
    CONSTRAINT `fk_notif_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
