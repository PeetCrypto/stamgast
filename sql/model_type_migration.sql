-- ============================================
-- Migration: Loyalty Tier Model Type
-- Voegt model_type (discount/bonus) en bonus_percentage toe
-- ============================================

ALTER TABLE `loyalty_tiers`
    ADD COLUMN `model_type` ENUM('discount', 'bonus') NOT NULL DEFAULT 'discount'
        AFTER `topup_amount_cents`,
    ADD COLUMN `bonus_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00
        AFTER `model_type`;

-- Index voor snelle filtering per model type
ALTER TABLE `loyalty_tiers`
    ADD INDEX `idx_model_type` (`tenant_id`, `model_type`);
