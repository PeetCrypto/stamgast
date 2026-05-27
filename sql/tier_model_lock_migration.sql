-- ============================================================
-- REGULR.vip — Tier Model Lock Migration
-- Adds tier_model_type column to tenants table
-- Locks the model choice (discount or bonus) per tenant
-- Once set, all packages must use the same model type
-- Reset only via superadmin "Verwijder alle pakketten"
-- ============================================================

ALTER TABLE `tenants`
    ADD COLUMN `tier_model_type` ENUM('discount','bonus') NULL DEFAULT NULL
    AFTER `points_enabled`;
