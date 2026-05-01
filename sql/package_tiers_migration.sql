-- ==========================================================================
-- REGULR.vip - Package Tiers Migration
-- Adds topup_amount_cents, is_active, sort_order to loyalty_tiers
-- ==========================================================================

SET NAMES utf8mb4;

-- Add topup_amount_cents: the fixed deposit amount for this package (min 10000 = €100)
ALTER TABLE `loyalty_tiers`
    ADD COLUMN `topup_amount_cents` INT NOT NULL DEFAULT 10000
    COMMENT 'Fixed top-up amount in cents for this package (minimum €100 = 10000)'
    AFTER `min_deposit_cents`;

-- Add is_active: admin can toggle packages on/off
ALTER TABLE `loyalty_tiers`
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '0 = package disabled by admin, 1 = active'
    AFTER `points_multiplier`;

-- Add sort_order: custom ordering of packages
ALTER TABLE `loyalty_tiers`
    ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0
    COMMENT 'Display order, lower = first'
    AFTER `is_active`;

-- Add updated_at timestamp
ALTER TABLE `loyalty_tiers`
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER `sort_order`;
