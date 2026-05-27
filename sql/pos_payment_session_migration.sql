-- ============================================================
-- REGULR.vip — POS Payment Sessions Migration
-- Nieuwe flow: bartender genereert QR, gast scant en bevestigt
-- ============================================================

CREATE TABLE IF NOT EXISTS `pos_payment_sessions` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `session_token`     VARCHAR(64) NOT NULL UNIQUE,
    `tenant_id`         INT NOT NULL,
    `bartender_id`      INT NOT NULL,

    -- Bedragen in cents (bartender voert in)
    `amount_alc_cents`  INT NOT NULL DEFAULT 0,
    `amount_food_cents` INT NOT NULL DEFAULT 0,

    -- Status machine: pending -> scanned -> confirmed/cancelled/expired/failed
    `status`            ENUM('pending','scanned','confirmed','cancelled','expired','failed') NOT NULL DEFAULT 'pending',

    -- Gast info (null tot gast de QR scant)
    `guest_user_id`     INT NULL DEFAULT NULL,
    `guest_name`        VARCHAR(255) NULL DEFAULT NULL,

    -- Discount info (berekend bij scan door server)
    `discount_alc_cents`  INT NOT NULL DEFAULT 0,
    `discount_food_cents` INT NOT NULL DEFAULT 0,
    `final_total_cents`   INT NOT NULL DEFAULT 0,

    -- Resultaat
    `transaction_id`    INT NULL DEFAULT NULL,
    `error_message`     TEXT NULL DEFAULT NULL,

    -- Timestamps
    `expires_at`        DATETIME NOT NULL,
    `scanned_at`        DATETIME NULL DEFAULT NULL,
    `confirmed_at`      DATETIME NULL DEFAULT NULL,
    `cancelled_at`      DATETIME NULL DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX `idx_session_token` (`session_token`),
    INDEX `idx_tenant_status` (`tenant_id`, `status`),
    INDEX `idx_bartender` (`bartender_id`),
    INDEX `idx_guest` (`guest_user_id`),
    INDEX `idx_expires` (`expires_at`),

    -- Foreign keys
    CONSTRAINT `fk_pos_session_tenant`    FOREIGN KEY (`tenant_id`)    REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pos_session_bartender` FOREIGN KEY (`bartender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pos_session_guest`     FOREIGN KEY (`guest_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pos_session_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
