-- ==========================================================================
-- WebAuthn Credentials & Session Keepalive Migratie
-- Ondersteunt FaceID/fingerprint authenticatie voor gast PWA
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `user_credentials` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `user_id`           INT             NOT NULL,
    `credential_id`     TEXT            NOT NULL,
    `public_key`        TEXT            NOT NULL,
    `counter`           INT             NOT NULL DEFAULT 0,
    `device_type`       VARCHAR(50)     NULL,
    `backup_eligible`   TINYINT(1)      DEFAULT 0,
    `backed_up`         TINYINT(1)      DEFAULT 0,
    `transports`        VARCHAR(255)    NULL,
    `aaguid`            VARCHAR(36)     NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`      TIMESTAMP       NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_creds_user` (`user_id`),
    UNIQUE KEY `uk_creds_credential_id` (`credential_id`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webauthn_challenges` (
    `id`                INT             NOT NULL AUTO_INCREMENT,
    `user_id`           INT             NOT NULL,
    `challenge`         VARCHAR(64)     NOT NULL,
    `type`              ENUM('registration','authentication') NOT NULL,
    `expires_at`        TIMESTAMP       NOT NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_challenges_user` (`user_id`),
    INDEX `idx_challenges_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
