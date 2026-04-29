-- ==========================================================================
-- PLATFORM FEE SYSTEM - DATABASE MIGRATION
-- Mollie Connect Marketplace + Platform Fee Ledger + Invoicing
-- ==========================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------
-- 1. ALTER TENANTS: Add platform fee & Mollie Connect columns (if not exist)
-- -------------------------------------------------------------------------

-- Helper function to add column if not exists
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists(IN tbl VARCHAR(64), IN col VARCHAR(64), IN coldef VARCHAR(255))
BEGIN
    IF NOT EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', col, ' ', coldef);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- Add each column if it does not exist
CALL add_column_if_not_exists('tenants', 'platform_fee_percentage', 'DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT ''Platform fee % (excl. BTW), set by superadmin only''');
CALL add_column_if_not_exists('tenants', 'platform_fee_min_cents', 'INT NOT NULL DEFAULT 25 COMMENT ''Minimum platform fee per transactie in cents (default €0,25), per-tenant configurable''');
CALL add_column_if_not_exists('tenants', 'mollie_connect_id', 'VARCHAR(255) NULL COMMENT ''Mollie Connect organization/resource ID (na onboarding)''');
CALL add_column_if_not_exists('tenants', 'mollie_connect_status', "ENUM('none','pending','active','suspended','revoked') NOT NULL DEFAULT 'none' COMMENT ''Mollie Connect status. NONE = niet aangemeld. ACTIVE = payments toegestaan.''");
CALL add_column_if_not_exists('tenants', 'invoice_period', "ENUM('week','month') NOT NULL DEFAULT 'month' COMMENT ''Verzamelfactuur frequentie''");
CALL add_column_if_not_exists('tenants', 'btw_number', 'VARCHAR(50) NULL COMMENT ''BTW nummer tenant (voor verzamelfactuur)''');
CALL add_column_if_not_exists('tenants', 'invoice_email', 'VARCHAR(255) NULL COMMENT ''Factuur e-mailadres (kan afwijken van contact_email)''');
CALL add_column_if_not_exists('tenants', 'platform_fee_note', 'TEXT NULL COMMENT ''Interne notitie superadmin over deze tenant (niet zichtbaar voor tenant)''');

DROP PROCEDURE add_column_if_not_exists;

-- -------------------------------------------------------------------------
-- 2. PLATFORM_INVOICES (Verzamelfacturen) — MUST be created BEFORE platform_fees
-- -------------------------------------------------------------------------
-- Maandelijks (of wekelijks) per tenant gegenereerd
-- BTW: 21% over de platform fee (dienstverlening)
-- Vermelding: "Verrekend via Mollie bij betaling"
-- -------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `platform_invoices` (
    `id`                  INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT             NOT NULL,
    `invoice_number`      VARCHAR(50)     NOT NULL COMMENT 'bijv. "PI-2026-05-001"',
    `period_start`        DATE            NOT NULL,
    `period_end`          DATE            NOT NULL,
    `period_type`         ENUM('week','month') NOT NULL,

    -- Financieel
    `transaction_count`   INT             NOT NULL DEFAULT 0 COMMENT 'Aantal deposits in deze periode',
    `gross_total_cents`   BIGINT          NOT NULL DEFAULT 0 COMMENT 'Totaal deposit bedrag',
    `fee_total_cents`     BIGINT          NOT NULL DEFAULT 0 COMMENT 'Totaal platform fee',
    `btw_percentage`      DECIMAL(5,2)    NOT NULL DEFAULT 21.00,
    `btw_amount_cents`    BIGINT          NOT NULL DEFAULT 0 COMMENT 'BTW over platform fee',
    `total_incl_btw_cents` BIGINT         NOT NULL DEFAULT 0 COMMENT 'fee_total + btw',

    -- Status
    `status`              ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    `pdf_path`            VARCHAR(255)    NULL COMMENT 'Pad naar PDF factuur bestand',
    `sent_at`             TIMESTAMP       NULL COMMENT 'Wanneer factuur naar tenant is verzonden',
    `paid_at`             TIMESTAMP       NULL COMMENT 'Wanneer factuur is betaald',
    `cancelled_at`        TIMESTAMP       NULL COMMENT 'Wanneer factuur is geannuleerd',
    `notes`               TEXT            NULL COMMENT 'Interne notities superadmin',

    `created_at`          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pi_number` (`invoice_number`),
    INDEX `idx_pi_tenant` (`tenant_id`),
    INDEX `idx_pi_status` (`status`),
    INDEX `idx_pi_period` (`period_start`, `period_end`),
    CONSTRAINT `fk_pi_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 3. PLATFORM_FEES (Fee Ledger - per transactie)
-- -------------------------------------------------------------------------
-- Waterdichte audit trail:
-- - fee_percentage wordt GESNAPSHOT per transactie
-- - fee_amount komt uit Mollie webhook (applicationFee.amount), NIET herberekend
-- - status flow: collected -> invoiced -> settled
-- -------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `platform_fees` (
    `id`                  INT             NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT             NOT NULL,
    `transaction_id`      INT             NOT NULL COMMENT 'FK naar transactions.id (deposit)',
    `mollie_payment_id`   VARCHAR(255)    NULL COMMENT 'Mollie payment ID voor cross-reference',
    `user_id`             INT             NOT NULL COMMENT 'Gast die de deposit deed',

    -- Bedragen (alles in cents)
    `gross_amount_cents`  INT             NOT NULL COMMENT 'Oorspronkelijk deposit bedrag',
    `fee_percentage`      DECIMAL(5,2)    NOT NULL COMMENT 'SNAPSHOT: fee % op moment van transactie',
    `fee_amount_cents`    INT             NOT NULL COMMENT 'Daadwerkelijk afgetroomd (uit Mollie applicationFee)',
    `net_amount_cents`    INT             NOT NULL COMMENT 'Wat naar tenant ging = gross - fee',
    `fee_min_cents`       INT             NOT NULL DEFAULT 0 COMMENT 'SNAPSHOT: minimum fee op moment van transactie',

    -- Mollie settlement
    `mollie_fee_cents`    INT             NULL COMMENT 'Mollie eigen transactiekosten (uit settlement)',
    `mollie_settlement_id` VARCHAR(255)   NULL COMMENT 'Mollie settlement reference',

    -- Invoice koppeling
    `status`              ENUM('collected','invoiced','settled') NOT NULL DEFAULT 'collected',
    `invoice_id`          INT             NULL COMMENT 'FK naar platform_invoices.id (NULL = nog niet gefactureerd)',
    `deposit_processed_at` TIMESTAMP      NULL COMMENT 'When wallet was credited (idempotency guard)',

    `created_at`          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pf_transaction` (`transaction_id`),
    INDEX `idx_pf_tenant` (`tenant_id`),
    INDEX `idx_pf_status` (`status`),
    INDEX `idx_pf_created` (`created_at`),
    INDEX `idx_pf_mollie_payment` (`mollie_payment_id`),
    CONSTRAINT `fk_pf_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `platform_invoices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 4. PLATFORM_FEE_LOG (Audit trail voor fee mutaties)
-- -------------------------------------------------------------------------
-- Logt elke mutatie: aanmaken, status wijziging, koppeling aan factuur
-- -------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `platform_fee_log` (
    `id`                  INT             NOT NULL AUTO_INCREMENT,
    `platform_fee_id`     INT             NOT NULL,
    `action`              VARCHAR(50)     NOT NULL COMMENT 'created | status_changed | invoice_linked',
    `old_value`           VARCHAR(255)    NULL COMMENT 'Vorige status/waarde',
    `new_value`           VARCHAR(255)    NULL COMMENT 'Nieuwe status/waarde',
    `actor_user_id`       INT             NULL COMMENT 'Superadmin die de actie uitvoerde (NULL = systeem/automatisch)',
    `ip_address`          VARCHAR(45)     NULL,
    `created_at`          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_pfl_fee` (`platform_fee_id`),
    INDEX `idx_pfl_action` (`action`),
    CONSTRAINT `fk_pfl_fee` FOREIGN KEY (`platform_fee_id`) REFERENCES `platform_fees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 5. Index hints voor performance
-- -------------------------------------------------------------------------
-- Extra composite index voor veelgebruikte superadmin query:
CREATE INDEX `idx_pf_tenant_status` ON `platform_fees` (`tenant_id`, `status`, `created_at`);
