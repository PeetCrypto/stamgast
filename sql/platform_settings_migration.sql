-- ============================================================
-- Platform Settings Migration
-- Key-value store for platform-level configuration
-- (Mollie Connect credentials, feature flags, etc.)
-- ============================================================

CREATE TABLE IF NOT EXISTS `platform_settings` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(128)   NOT NULL UNIQUE,
    `setting_value` TEXT           DEFAULT NULL,
    `encrypted`     TINYINT(1)     NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Mollie Connect placeholders
INSERT IGNORE INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`) VALUES
    ('mollie_connect_api_key',      '', 1),
    ('mollie_connect_client_id',    '', 0),
    ('mollie_connect_client_secret','', 1),
    ('mollie_mode_default',         'mock', 0);
