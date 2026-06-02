<?php
declare(strict_types=1);

/**
 * TimezoneService
 *
 * Beheert per-tenant timezone instellingen. Named timezone strings
 * (bijv. 'Europe/Amsterdam') lossen automatisch zomertijd/wintertijd op
 * via PHP's DateTimeZone — geen statische offsets nodig.
 *
 *Gebruik:
 *   TimezoneService::init($db, $tenantId);  // roep aan vóór alle andere logica
 *   TimezoneService::getCurrent();            // retourneert huidige timezone string
 */

class TimezoneService
{
    private static string $timezone = 'Europe/Amsterdam';
    private static bool $initialized = false;

    /**
     * Geldige timezone identifiers die we accepteren.
     * Beperkt tot veilige, bekende zones — voorkomt injection via user input.
     */
    private const ALLOWED_ZONES = [
        // Europa
        'Europe/Amsterdam',
        'Europe/London',
        'Europe/Paris',
        'Europe/Berlin',
        'Europe/Brussels',
        'Europe/Madrid',
        'Europe/Rome',
        'Europe/Vienna',
        'Europe/Stockholm',
        'Europe/Copenhagen',
        'Europe/Oslo',
        'Europe/Dublin',
        'Europe/Lisbon',
        'Europe/Zurich',
        'Europe/Prague',
        'Europe/Warsaw',
        'Europe/Budapest',
        'Europe/Helsinki',
        'Europe/Athens',
        'Europe/Istanbul',
        'Europe/Moscow',
        // Noord-Amerika
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Toronto',
        'America/Vancouver',
        'America/Mexico_City',
        // Azië
        'Asia/Tokyo',
        'Asia/Shanghai',
        'Asia/Hong_Kong',
        'Asia/Singapore',
        'Asia/Dubai',
        'Asia/Bangkok',
        'Asia/Jakarta',
        // Oceanië
        'Australia/Sydney',
        'Australia/Melbourne',
        'Pacific/Auckland',
        // UTC
        'UTC',
    ];

    /**
     * Initialiseer de timezone voor de huidige request.
     * 1. Stel PHP default timezone in (gebruikt door date(), DateTime, etc.)
     * 2. Stel MySQL session time_zone in (gebruikt door NOW(), CURDATE(), etc.)
     */
    public static function init(PDO $db, ?int $tenantId = null): void
    {
        $tz = self::resolveTimezone($db, $tenantId);

        // 1. PHP timezone (named zone — volledige zomertijd/wintertijd support)
        date_default_timezone_set($tz);
        self::$timezone = $tz;

        // 2. MySQL session timezone
        //    Probeer eerst named timezone (bijv. 'Europe/Amsterdam').
        //    Als MySQL geen timezone tables heeft (Laragon lokaal), val terug
        //    op numerieke offset berekend via PHP DateTimeZone.
        //    LET OP: numerieke offset is statisch en volgt geen zomertijd!
        //    Op Hostinger (wel timezone tables) werkt de named zone correct.
        $mysqlOffset = self::tzToOffset($tz);
        try {
            // Probeer named timezone
            $db->exec("SET time_zone = '" . $tz . "'");
        } catch (\PDOException $e) {
            // Fallback: numerieke offset (bijv. '+02:00' of '+01:00')
            try {
                $db->exec("SET time_zone = '" . $mysqlOffset . "'");
            } catch (\PDOException $e2) {
                error_log('TimezoneService: MySQL SET time_zone failed for both named (' . $tz . ') and offset (' . $mysqlOffset . '): ' . $e2->getMessage());
            }
        }

        self::$initialized = true;
    }

    /**
     * Converteer een named timezone naar een MySQL-compatible numerieke offset.
     * Bijv. 'Europe/Amsterdam' → '+02:00' (zomer) of '+01:00' (winter).
     * Dit is nodig omdat Laragon lokale MySQL geen timezone tables heeft.
     */
    private static function tzToOffset(string $tz): string
    {
        try {
            $dt = new DateTime('now', new DateTimeZone($tz));
            return $dt->format('P'); // bijv. '+02:00' of '+01:00'
        } catch (\Exception $e) {
            return '+00:00'; // UTC fallback
        }
    }

    /**
     * Resolve timezone: uit DB als tenant bekent, anders uit session, anders default.
     */
    private static function resolveTimezone(PDO $db, ?int $tenantId): string
    {
        // Probeer uit database als tenant_id bekend
        if ($tenantId !== null) {
            try {
                $stmt = $db->prepare('SELECT `timezone` FROM `tenants` WHERE `id` = :id LIMIT 1');
                $stmt->execute([':id' => $tenantId]);
                $tz = $stmt->fetchColumn();
                if ($tz && self::isValidTimezone($tz)) {
                    return $tz;
                }
            } catch (\PDOException $e) {
                // Kolom bestaat mogelijk nog niet (migratie niet gedraaid)
                error_log('TimezoneService: DB lookup failed: ' . $e->getMessage());
            }
        }

        // Fallback: uit session (gebruiker is al ingelogd)
        if (isset($_SESSION['timezone']) && self::isValidTimezone($_SESSION['timezone'])) {
            return $_SESSION['timezone'];
        }

        // Ultimate fallback
        return 'Europe/Amsterdam';
    }

    /**
     * Valideer of een timezone string in onze allowlist zit.
     * Daarnaast dubbelcheck met DateTimeZone constructor.
     */
    private static function isValidTimezone(string $tz): bool
    {
        if (!in_array($tz, self::ALLOWED_ZONES, true)) {
            return false;
        }

        // Extra safety: PHP's Ondersteent deze nu werkelijk?
        try {
            new DateTimeZone($tz);
            return true;
        } catch (\Exception$e) {
            return false;
        }
    }

    /**
     * Huidige actieve timezone string ophalen.
     */
    public static function getCurrent(): string
    {
        return self::$timezone;
    }

    /**
     * Alle geldige timezones Returneren (voor admin dropdowns).
     */
    public static function getAllowedTimezones(): array
    {
        return self::ALLOWED_ZONES;
    }

    /**
     * Geeft contextinfo terug voor debugging.
     */
    public static function getStatus(): array
    {
        $now = new DateTime('now', new DateTimeZone(self::$timezone));
        return [
            'php_timezone'     => date_default_timezone_get(),
            'service_timezone' => self::$timezone,
            'initialized'      => self::$initialized,
            'local_time'       => $now->format('Y-m-d H:i:s'),
            'utc_offset'       => $now->format('P'), // bijv. +02:00 (zomer) of +01:00 (winter)
            'is_dst'           => (bool) $now->format('I'),
        ];
    }

    // Prevent instantiation/cloning — static service only
    private function __construct() {}
    private function __clone() {}
}
