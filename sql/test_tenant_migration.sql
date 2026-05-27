-- ============================================================
-- REGULR.vip — Test Tenant Migration
-- Adds is_test column to tenants table
-- Allows superadmin to mark a tenant as "test" and purge its data
-- ============================================================

ALTER TABLE `tenants`
    ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `is_active`;
