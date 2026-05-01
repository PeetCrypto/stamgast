-- ==========================================================================
-- REGULR.vip LOYALTY PLATFORM - SEED DATA
-- Test data for development (1 tenant, admins, guests, tiers)
--
-- PHASE 1 (MOCK): Tenant starts in mock mode with connect_status='active'
--   → All payment flows work without real Mollie credentials
-- PHASE 2 (TEST):  Superadmin configures Mollie test keys in platform_settings
--   → Tenant switched to mollie_status='test', real Mollie test API used
-- PHASE 3 (LIVE):  Superadmin configures Mollie live keys
--   → Tenant switched to mollie_status='live', real payments
-- ==========================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------
-- 1. TENANT: "Cafe De REGULR.vip" (demo establishment)
--    mollie_status='mock' + mollie_connect_status='active' = mock payments
-- -------------------------------------------------------------------------
INSERT INTO `tenants` (`uuid`, `name`, `slug`, `brand_color`, `secondary_color`, `secret_key`, `mollie_status`, `mollie_connect_status`, `mollie_connect_id`, `platform_fee_percentage`, `platform_fee_min_cents`, `whitelisted_ips`, `feature_push`, `feature_marketing`) VALUES
('550e8400-e29b-41d4-a716-446655440000', 'Cafe De REGULR.vip', 'cafe-de-regulr', '#FFC107', '#FF9800', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2', 'mock', 'active', 'org_mock_test', 1.00, 25, '127.0.0.1\n::1\n192.168.1.0/24', 1, 1);

-- -------------------------------------------------------------------------
-- 2. USERS
--    Passwords hashed with Argon2id + APP_PEPPER.
-- -------------------------------------------------------------------------
-- Super-Admin (NULL tenant_id - platform level, NOT tenant level)
-- Password: "Admin123!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(NULL, 'admin@stamgast.nl', '$argon2id$v=19$m=65536,t=4,p=1$cUJaR1YyRzE3bVRyOUd5bQ$zevt5kZ94uWMRTe3oOk+ksBy0XZu52xq6Z9mlO/raJQ', 'superadmin', 'Admin', 'User', '1990-01-01');

-- Admin / Manager (tenant owner)
-- Password: "Manager123!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'manager@test.nl', '$argon2id$v=19$m=65536,t=4,p=1$TlBkRDdyMjd6VHJENlRRSg$eHsEljEiVSA4LiAChW/fxDohQaUuUQm1OoM19eCdeJM', 'admin', 'Manager', 'Test', '1985-06-15');

-- Bartender
-- Password: "Bartend3r!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'bartender@test.nl', '$argon2id$v=19$m=65536,t=4,p=1$OUprQjFLNS9JL21UMFFwaw$OdBgKNWnRTwXziH3BVpa/RyYPvD+EZ0aCRS5VS+S2Xg', 'bartender', 'Bart', 'Tender', '1992-03-22');

-- Guest
-- Password: "Guest123!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'guest@test.nl', '$argon2id$v=19$m=65536,t=4,p=1$Yk5iVDR6bzg4UjF2LmNrYg$z0Si+fV4bAE2F4bZOH91tegqq71yZJOizlvRKT1LPJM', 'guest', 'Guest', 'User', '1995-08-10');

-- -------------------------------------------------------------------------
-- 3. WALLETS (auto-created per tenant user — NOT for superadmin)
-- Superadmin (user_id=1) has NO wallet: platform manager, not a tenant guest.
-- -------------------------------------------------------------------------
INSERT INTO `wallets` (`user_id`, `tenant_id`, `balance_cents`, `points_cents`) VALUES
(2, 1, 5000, 5000),
(3, 1, 0, 0),
(4, 1, 0, 0);

-- -------------------------------------------------------------------------
-- 4. LOYALTY TIERS (Packages)
-- -------------------------------------------------------------------------
INSERT INTO `loyalty_tiers` (`tenant_id`, `name`, `min_deposit_cents`, `topup_amount_cents`, `alcohol_discount_perc`, `food_discount_perc`, `points_multiplier`, `is_active`, `sort_order`) VALUES
(1, 'Bronze',    0,       5000,   0.00,  0.00, 1.00, 1, 1),
(1, 'Silver',   10000,  10000,   5.00,  5.00, 1.25, 1, 2),
(1, 'Gold',     50000,  25000,  10.00, 10.00, 1.50, 1, 3),
(1, 'Platinum', 200000, 50000,  20.00, 15.00, 2.00, 1, 4);

-- -------------------------------------------------------------------------
-- LOGIN CREDENTIALS (for testing)
-- -------------------------------------------------------------------------
-- Role          Email                Password      Name           Saldo
-- ──────────────────────────────────────────────────────────────────────
-- Superadmin    admin@stamgast.nl    Admin123!     Admin User     n/a
-- Admin         manager@test.nl      Manager123!   Manager Test   €50
-- Bartender     bartender@test.nl    Bartend3r!    Bart Tender    €0
-- Guest         guest@test.nl        Guest123!     Guest User     €0
