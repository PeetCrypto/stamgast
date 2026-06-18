-- Migration: Mollie Connect Onboarding Status Cache
-- Caches the live onboarding status of a connected tenant so the superadmin
-- tenant detail page can show whether the account is ready for live payments
-- (canReceivePayments) without hitting the Mollie API on every page load.
--
-- The cache is refreshed on demand via /api/superadmin/mollie-status.

ALTER TABLE `tenants`
    ADD COLUMN `mollie_connect_onboarding_status`   VARCHAR(20)  NULL DEFAULT NULL
        COMMENT 'Mollie onboarding status: needs-data | in-review | completed | unknown'
        AFTER `mollie_connect_status`,
    ADD COLUMN `mollie_connect_can_receive_payments` TINYINT(1)  NULL DEFAULT NULL
        COMMENT '1 = tenant can receive live payments, 0 = not yet, NULL = unknown'
        AFTER `mollie_connect_onboarding_status`,
    ADD COLUMN `mollie_connect_status_checked_at`    DATETIME    NULL DEFAULT NULL
        COMMENT 'UTC timestamp of the last live onboarding check'
        AFTER `mollie_connect_can_receive_payments`;
