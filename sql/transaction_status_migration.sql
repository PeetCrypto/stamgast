-- =====================================================================
-- Transaction Status Migration
-- Adds a `status` column to the `transactions` table to track
-- payment lifecycle: pending → paid | failed | expired | cancelled
--
-- Problem: Deposits created via Mollie (test/live) were stored without
-- a status. Failed/expired payments appeared in the wallet overview
-- as successful deposits with no visual indication of failure.
--
-- Solution: Track status per transaction. Only status='paid' deposits
-- contribute to the wallet balance. Non-paid deposits are visible in
-- history but marked with warning indicators.
-- =====================================================================

-- 1. Add status column (default 'paid' for backward compatibility)
ALTER TABLE `transactions`
    ADD COLUMN `status`
    ENUM('pending','paid','failed','expired','cancelled')
    NOT NULL DEFAULT 'paid'
    AFTER `mollie_payment_id`;

-- 2. Add index for status filtering
ALTER TABLE `transactions`
    ADD INDEX `idx_trans_status` (`status`);

-- 3. Backfill: deposits with a Mollie payment ID that have NOT been
--    processed yet (no matching platform_fees.deposit_processed_at)
--    should be marked as 'pending' so they show correctly in the UI.
--    Already-processed deposits remain 'paid' (the default).
UPDATE `transactions` t
LEFT JOIN `platform_fees` pf ON pf.transaction_id = t.id AND pf.deposit_processed_at IS NOT NULL
SET t.status = 'pending'
WHERE t.type = 'deposit'
  AND t.mollie_payment_id IS NOT NULL
  AND pf.id IS NULL;
