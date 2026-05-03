-- Password Reset Tokens
-- Stores time-limited tokens for self-service password reset

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `tenant_id` INT NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token` (`token`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_email_tenant` (`email`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
