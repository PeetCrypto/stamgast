<?php
declare(strict_types=1);

/**
 * REGULR.vip — Browser-Accessible Migration Runner
 *
 * Runs all pending SQL migrations via the browser (for shared hosting without SSH).
 * Wraps the CLI migrate.php with HTML output instead of ANSI color codes.
 *
 * Usage:
 *   https://app.regulr.vip/migrate
 *   (must be logged in as superadmin — enforced by index.php router)
 *
 * Security:
 *   The /migrate route in index.php requires superadmin session.
 *   No additional key needed.
 */

// ── 1. Define HTML color functions BEFORE including migrate.php ──────────────
//    migrate.php uses function_exists() guards so it won't redefine them.
function green(string $text): string  { return '<span class="ok">' . htmlspecialchars($text) . '</span>'; }
function red(string $text): string    { return '<span class="err">' . htmlspecialchars($text) . '</span>'; }
function yellow(string $text): string { return '<span class="warn">' . htmlspecialchars($text) . '</span>'; }
function bold(string $text): string   { return '<strong>' . htmlspecialchars($text) . '</strong>'; }
function dim(string $text): string    { return '<span class="dim">' . htmlspecialchars($text) . '</span>'; }

// ── 2. Allow web access to migrate.php ───────────────────────────────────────
define('REGULR_MIGRATE_ALLOW_WEB', true);

// ── 3. Load project bootstrap ────────────────────────────────────────────────
//    Populates getenv() with DB_HOST, DB_NAME, DB_USER, DB_PASS, etc.
//    index.php already loaded config, but include guard for direct access
if (!defined('APP_ENV')) {
    require_once dirname(__DIR__) . '/config/load_env.php';
    require_once dirname(__DIR__) . '/config/app.php';
}

// ── 4. Security gate ─────────────────────────────────────────────────────────
// When included via /migrate route in index.php, auth is already checked.
// When accessed directly, require superadmin session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h2 style="color:#f44336">403 Forbidden</h2>'
        . '<p>Alleen de superadmin kan migraties uitvoeren.</p>'
        . '<p><a href="/login">Inloggen</a></p>'
        . '</body></html>');
}

// ── 5. Output HTML header ────────────────────────────────────────────────────
echo '<!DOCTYPE html>' . "\n";
echo '<html lang="nl">' . "\n";
echo '<head>' . "\n";
echo '  <meta charset="utf-8">' . "\n";
echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
echo '  <title>REGULR.vip — Database Migrations</title>' . "\n";
echo '  <style>' . "\n";
echo '    * { margin: 0; padding: 0; box-sizing: border-box; }' . "\n";
echo '    body {' . "\n";
echo '      font-family: "SF Mono", "Cascadia Code", "Fira Code", Consolas, monospace;' . "\n";
echo '      background: #0d1117;' . "\n";
echo '      color: #c9d1d9;' . "\n";
echo '      padding: 24px;' . "\n";
echo '      line-height: 1.6;' . "\n";
echo '    }' . "\n";
echo '    pre {' . "\n";
echo '      white-space: pre-wrap;' . "\n";
echo '      word-wrap: break-word;' . "\n";
echo '      font-size: 13px;' . "\n";
echo '      line-height: 1.7;' . "\n";
echo '    }' . "\n";
echo '    .ok   { color: #3fb950; }' . "\n";
echo '    .err  { color: #f85149; }' . "\n";
echo '    .warn { color: #d29922; }' . "\n";
echo '    .dim  { color: #484f58; }' . "\n";
echo '    strong { color: #e6edf3; }' . "\n";
echo '  </style>' . "\n";
echo '</head>' . "\n";
echo '<body>' . "\n";
echo '<pre>' . "\n";

// Flush output so the user sees the HTML header immediately
if (ob_get_level() === 0) {
    ob_start();
}
ob_flush();
flush();

// ── 6. Register shutdown to close HTML tags ───────────────────────────────────
//    migrate.php always calls exit(0) or exit(1), so we need shutdown handler
//    to close the <pre>, </body>, </html> tags.
register_shutdown_function(function() {
    // Flush any remaining output
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    echo "\n</pre>\n</body>\n</html>";
});

// ── 7. Run the migrations ────────────────────────────────────────────────────
require __DIR__ . '/migrate_new.php';
