-- ============================================================
-- Tip/Fooi Feature Migration
-- Adds configurable tip amounts to tenants, and tip tracking
-- to transactions and POS payment sessions.
-- ============================================================

-- 1. Add tip amount columns to tenants (Admin configurable, in cents)
ALTER TABLE `tenants`
    ADD COLUMN `tip_amount_1_cents` INT NOT NULL DEFAULT 100  COMMENT 'Preset tip 1 (e.g. €1.00)' AFTER `tier_model_type`,
    ADD COLUMN `tip_amount_2_cents` INT NOT NULL DEFAULT 250  COMMENT 'Preset tip 2 (e.g. €2.50)' AFTER `tip_amount_1_cents`,
    ADD COLUMN `tip_amount_3_cents` INT NOT NULL DEFAULT 500  COMMENT 'Preset tip 3 (e.g. €5.00)' AFTER `tip_amount_2_cents`;

-- 2. Add tip tracking to transactions
ALTER TABLE `transactions`
    ADD COLUMN `tip_cents` INT NOT NULL DEFAULT 0 COMMENT 'Tip amount in cents' AFTER `btw_total_cents`;

-- 3. Add tip tracking to POS payment sessions
ALTER TABLE `pos_payment_sessions`
    ADD COLUMN `tip_cents` INT NOT NULL DEFAULT 0 COMMENT 'Tip chosen by guest, in cents' AFTER `final_total_cents`;
