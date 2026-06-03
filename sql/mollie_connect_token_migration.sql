-- Migration: Mollie Connect Access Token per Tenant
-- Adds mollie_connect_access_token column to store the OAuth access token
-- received during the Mollie Connect onboarding flow.
-- This token is required for creating payments with applicationFee (Connect).

ALTER TABLE `tenants`
    ADD COLUMN `mollie_connect_access_token` TEXT NULL AFTER `mollie_connect_id`;
