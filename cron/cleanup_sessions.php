<?php
/**
 * REGULR.vip - Cron: Cleanup Expired Sessions
 *
 * Verwijdert verlopen rijen uit de `sessions` tabel (database-backed PHP
 * sessions). Voorkomt dat de tabel eindeloos groeit op de lange termijn.
 *
 * De cutoff is SESSION_TIMEOUT_GUEST (5 jaar) — de langste actieve
 * sessielevensduur in de app. Hierdoor worden NOOIT actieve gasten
 * uitgelogd; alleen sessies die al jaren inactief zijn worden opgeruimd.
 *
 * Local test:  php cron/cleanup_sessions.php
 * Hostinger:   php /home/xxx/domains/app.regulr.vip/public_html/cron/cleanup_sessions.php
 *              1x per dag aanraden.
 */

// --- Bootstrap (zonder session/auth — CLI context) ---
require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Cutoff: nooit korter dan de gast-timeout, anders loggen we actieve
    // gasten uit die niet elke dag terugkomen.
    $maxLifetime = defined('SESSION_TIMEOUT_GUEST') ? SESSION_TIMEOUT_GUEST : 157680000; // 5 jaar
    $cutoff = time() - (int) $maxLifetime;

    $stmt = $db->prepare('DELETE FROM `sessions` WHERE `last_activity` < :cutoff');
    $stmt->execute([':cutoff' => $cutoff]);
    $deleted = (int) $stmt->rowCount();

    // Aantal actieve sessies rapporteren (handig voor monitoring)
    $countStmt = $db->query('SELECT COUNT(*) FROM `sessions`');
    $remaining = (int) $countStmt->fetchColumn();

    $timestamp = date('c');
    $line = "[{$timestamp}] Sessions GC: {$deleted} expired removed, {$remaining} active remaining";

    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'timestamp' => $timestamp,
            'deleted'   => $deleted,
            'remaining' => $remaining,
        ]);
    }
} catch (\Throwable $e) {
    $errorMsg = '[' . date('c') . '] CRON ERROR (cleanup_sessions): ' . $e->getMessage();
    error_log($errorMsg);

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMsg . PHP_EOL);
        exit(1);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}
