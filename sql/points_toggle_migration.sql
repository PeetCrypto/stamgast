-- ============================================================
-- Points Toggle Migration
-- Date: 2026-05-19
-- Description: Adds points_enabled toggle to tenants table.
--              When disabled, no points are earned or shown for that tenant.
--              Admin can toggle this per tenant via the settings page.
-- ============================================================

ALTER TABLE `tenants`
    ADD COLUMN `points_enabled` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'true = punten sparen aan, false = geen punten systeem'
        AFTER `feature_marketing`;
