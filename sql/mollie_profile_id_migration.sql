-- Migration: Add mollie_connect_profile_id column to tenants table
-- Stores the Mollie website profile ID (e.g. pfl_XXXXXXXXXX) fetched during Connect onboarding.
-- Required for payment creation — Mollie returns 422 "A website profile is required for payments" without it.

ALTER TABLE `tenants`
    ADD COLUMN `mollie_connect_profile_id` VARCHAR(50) NULL DEFAULT NULL
    AFTER `mollie_connect_access_token`;
