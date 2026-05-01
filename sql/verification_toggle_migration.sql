-- ============================================================
-- Verification Toggle Migration
-- Date: 2026-04-30
-- Description: Adds verification_required toggle to tenants table.
--              When disabled, new guests are auto-activated on registration.
-- ============================================================

-- ============================================================
-- 1. ALTER TABLE `tenants` — Verification toggle
-- ============================================================
ALTER TABLE `tenants`
    ADD COLUMN `verification_required` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'true = gasten moeten geverifieerd worden, false = auto-active'
        AFTER `verification_max_attempts`;
