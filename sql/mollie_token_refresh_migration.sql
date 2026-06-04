-- Migration: Mollie Connect Token Refresh Support
-- Adds refresh_token and token_expires_at columns to the tenants table
-- so that expired OAuth access tokens can be automatically refreshed
-- without requiring the admin to manually re-authorize.

ALTER TABLE `tenants`
    ADD COLUMN `mollie_connect_refresh_token` TEXT NULL AFTER `mollie_connect_access_token`,
    ADD COLUMN `mollie_connect_token_expires_at` DATETIME NULL AFTER `mollie_connect_refresh_token`;
