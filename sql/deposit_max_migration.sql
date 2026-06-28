-- ============================================================
-- Deposit Limits per Tenant
-- Date: 2026-06-28
-- Description: Adds deposit_min_cents and deposit_max_cents columns to tenants table.
--              Allows superadmin to configure deposit limits per tenant.
--              Defaults: min=10000 (€100), max=50000 (€500)
-- ============================================================

-- Minimum deposit amount in cents
ALTER TABLE `tenants`
    ADD COLUMN `deposit_min_cents` INT NOT NULL DEFAULT 10000
        COMMENT 'Minimum deposit amount in cents (default 10000 = €100)';

-- Maximum deposit amount in cents
ALTER TABLE `tenants`
    ADD COLUMN `deposit_max_cents` INT NOT NULL DEFAULT 50000
        COMMENT 'Maximum deposit amount in cents (default 50000 = €500)';