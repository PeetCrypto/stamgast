-- ==========================================================================
-- REGULR.vip - Test Package Migration
-- Adds is_test_package flag to loyalty_tiers
--
-- A test package (€0.01 topup → €10 bonus) is auto-created when a tenant's
-- Test Modus (is_test) is enabled, and auto-removed when disabled.
-- This flag marks such packages so they can be:
--   - excluded from loyalty-level calculation (determineTier)
--   - hidden from guests unless the tenant is in test mode (defensive filter)
--   - protected from manual admin edit/delete
-- ==========================================================================

SET NAMES utf8mb4;

ALTER TABLE `loyalty_tiers`
    ADD COLUMN `is_test_package` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '0 = normal package, 1 = auto-managed test package (only visible when tenant.is_test=1)'
    AFTER `is_active`;

-- Index for efficient filtering of test packages per tenant
ALTER TABLE `loyalty_tiers`
    ADD INDEX `idx_test_package` (`tenant_id`, `is_test_package`);
