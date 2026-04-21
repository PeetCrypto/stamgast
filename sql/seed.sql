-- ==========================================================================
-- STAMGAST LOYALTY PLATFORM - SEED DATA
-- Test data for development (1 tenant, admins, guests, tiers)
-- ==========================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------
-- 1. TENANT: "Cafe De Stamgast" (demo establishment)
-- -------------------------------------------------------------------------
INSERT INTO `tenants` (`uuid`, `name`, `slug`, `brand_color`, `secondary_color`, `secret_key`, `mollie_status`, `whitelisted_ips`, `feature_push`, `feature_marketing`) VALUES
('550e8400-e29b-41d4-a716-446655440000', 'Cafe De Stamgast', 'cafe-de-stamgast', '#FFC107', '#FF9800', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2', 'mock', '127.0.0.1\n::1\n192.168.1.0/24', 1, 1);

-- -------------------------------------------------------------------------
-- 2. USERS
-- -------------------------------------------------------------------------
-- Super-Admin (no tenant - platform level)
-- Password: "Admin123!" hashed with Argon2id
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'superadmin@stamgast.app', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforsuperadmin001', 'superadmin', 'Super', 'Admin', '1990-01-01');

-- Admin (tenant owner)
-- Password: "Admin123!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'admin@stamgast.app', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforadmin00000002', 'admin', 'Jan', 'de Vries', '1985-06-15');

-- Bartender
-- Password: "Bar123!"
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'bartender@stamgast.app', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforbartender03', 'bartender', 'Piet', 'Bakker', '1992-03-22');

-- Guests
INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `birthdate`) VALUES
(1, 'guest1@example.com', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforguest1000004', 'guest', 'Marie', 'Jansen', '1995-08-10'),
(1, 'guest2@example.com', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforguest2000005', 'guest', 'Klaas', 'Visser', '2000-12-01'),
(1, 'guest3@example.com', '$argon2id$v=19$m=65536,t=4,p=1$dummyhashforguest3000006', 'guest', 'Sophie', 'Mulder', '1998-04-18');

-- -------------------------------------------------------------------------
-- 3. WALLETS (auto-created per user)
-- -------------------------------------------------------------------------
INSERT INTO `wallets` (`user_id`, `tenant_id`, `balance_cents`, `points_cents`) VALUES
(1, 1, 0, 0),
(2, 1, 0, 0),
(3, 1, 0, 0),
(4, 1, 15000, 15000),
(5, 1, 7500, 7500),
(6, 1, 500, 500);

-- -------------------------------------------------------------------------
-- 4. LOYALTY TIERS
-- -------------------------------------------------------------------------
INSERT INTO `loyalty_tiers` (`tenant_id`, `name`, `min_deposit_cents`, `alcohol_discount_perc`, `food_discount_perc`, `points_multiplier`) VALUES
(1, 'Bronze',  0,      0.00,  0.00, 1.00),
(1, 'Silver',  10000,  5.00,  5.00, 1.25),
(1, 'Gold',    50000, 10.00, 10.00, 1.50),
(1, 'Platinum', 200000, 20.00, 15.00, 2.00);

-- NOTE: Password hashes above are DUMMY placeholders.
-- Run this to generate real hashes in PHP:
--   echo password_hash('Admin123!', PASSWORD_ARGON2ID);
