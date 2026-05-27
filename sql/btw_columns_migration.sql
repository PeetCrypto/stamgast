-- ============================================================
-- REGULR.vip — BTW Columns Migration
-- Voegt BTW-tracking toe aan de transactions tabel
-- zodat de admin dag/week/maand overzichten heeft voor de boekhouding
--
-- BTW tarieven NL 2025:
--   Alcohol: 21%
--   Food:     9%
--
-- BTW wordt berekend over het NETTO bedrag (na korting)
-- ============================================================

ALTER TABLE `transactions`
    ADD COLUMN `btw_alc_cents`  INT NOT NULL DEFAULT 0 COMMENT 'BTW over alcohol (21%) in cents',
    ADD COLUMN `btw_food_cents` INT NOT NULL DEFAULT 0 COMMENT 'BTW over food (9%) in cents',
    ADD COLUMN `btw_total_cents` INT NOT NULL DEFAULT 0 COMMENT 'Totale BTW in cents';
