-- ============================================================
-- REGULR.vip — Bonus Cents Migration
-- Adds bonus_cents column to loyalty_tiers for fixed bonus amounts
-- instead of percentage-based bonuses.
--
-- Bonus model: admin enters fixed bonus amount per package
-- Example: Stort €100 → bonus €10 (total credit €110)
-- Example: Stort €150 → bonus €20 (total credit €170)
-- ============================================================

ALTER TABLE `loyalty_tiers`
    ADD COLUMN `bonus_cents` INT NOT NULL DEFAULT 0
    COMMENT 'Fixed bonus amount in cents (bonus model). Overrides bonus_percentage when > 0.'
    AFTER `bonus_percentage`;
