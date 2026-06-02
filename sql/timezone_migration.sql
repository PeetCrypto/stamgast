-- ==========================================================================
-- REGULR.vip — TIMEZONE MIGRATION
-- Voegt een `timezone` kolom toe aan de tenants tabel zodat elke
-- tenant een eigen named timezone kan hebben (bijv. 'Europe/Amsterdam').
--
-- Named timezone strings (niet statische offsets zoals '+02:00') lossen
-- automatisch zomertijd/wintertijd op via PHP DateTimeZone en MySQL
-- time_zone tables.
--
-- Default: 'Europe/Amsterdam' (CET/CEST, UTC+1/+2)
-- ==========================================================================

-- Voeg timezone kolom toe aan tenants tabel
ALTER TABLE `tenants`
    ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/Amsterdam'
    AFTER `country`;

-- Controleer of de MySQL server named timezones kent
-- (Hostinger shared hosting heeft dit doorgaans al geladen)
-- Als niet, dan valt de app terug via PHP DateTimeZone.
-- Om handmatig te laden (alleen als root, meestal niet nodig):
-- mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql
