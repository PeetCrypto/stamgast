-- ============================================================
-- REGULR.vip — Bonus Tiers Configuration Migration
-- Updates existing loyalty tiers to bonus model with correct
-- bonus_cents values matching the UI package display.
--
-- Package bonus structure:
--   Bronze:  €100 topup → €10 bonus (total €110 credited)
--   Silver:  €150 topup → €20 bonus (total €170 credited)
--   Gold:    €200 topup → €30 bonus (total €230 credited)
--   Platinum: €500 topup → €75 bonus (total €575 credited)
--
-- Also updates topup_amount_cents to match the UI if they
-- differ from the seed defaults.
-- ============================================================

-- Update Bronze: €100 topup, €10 bonus
UPDATE `loyalty_tiers`
SET `model_type` = 'bonus',
    `bonus_cents` = 1000,
    `bonus_percentage` = 0.00
WHERE `name` = 'Bronze' AND `tenant_id` = 1;

-- Update Silver: €150 topup, €20 bonus
UPDATE `loyalty_tiers`
SET `model_type` = 'bonus',
    `bonus_cents` = 2000,
    `bonus_percentage` = 0.00,
    `topup_amount_cents` = 15000
WHERE `name` = 'Silver' AND `tenant_id` = 1;

-- Update Gold: €200 topup, €30 bonus
UPDATE `loyalty_tiers`
SET `model_type` = 'bonus',
    `bonus_cents` = 3000,
    `bonus_percentage` = 0.00,
    `topup_amount_cents` = 20000
WHERE `name` = 'Gold' AND `tenant_id` = 1;

-- Update Platinum: €500 topup, €75 bonus
UPDATE `loyalty_tiers`
SET `model_type` = 'bonus',
    `bonus_cents` = 7500,
    `bonus_percentage` = 0.00
WHERE `name` = 'Platinum' AND `tenant_id` = 1;
