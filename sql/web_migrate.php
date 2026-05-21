<?php
declare(strict_types=1);

/**
 * REGULR.vip — Browser-Accessible Migration Runner
 *
 * Runs all pending SQL migrations via the browser (for shared hosting without SSH).
 * Wraps the CLI migrate.php with HTML output instead of ANSI color codes.
 *
 * Usage:
 *   https://app.regulr.vip/sql/web_migrate.php?key=YOUR_MIGRATE_KEY
 *
 * Security:
 *   Requires MIGRATE_KEY in .env file. The ?key= URL parameter must match exactly.
 *   Uses hash_equals() for timing-safe comparison.
 *
 * Requirements:
 *   - MIGRATE_KEY must be set in .env
 *   - sql/migrate.php must exist (the actual migration runner)
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
require_once dirname(__DIR__) . '/config/load_env.php';
require_once dirname(__DIR__) . '/config/app.php';

// ── 4. Security gate ─────────────────────────────────────────────────────────
$migrateKey  = getenv('MIGRATE_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';

if (empty($migrateKey)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h2 style="color:#f44336">403 Forbidden</h2>'
        . '<p>MIGRATE_KEY is not configured in .env</p>'
        . '<p>Add <code>MIGRATE_KEY=your-secret-string</code> to your .env file.</p>'
        . '</body></html>');
}

if (empty($providedKey) || !hash_equals($migrateKey, $providedKey)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h2 style="color:#f44336">403 Forbidden</h2>'
        . '<p>Invalid or missing migration key.</p>'
        . '<p>Usage: <code>web_migrate.php?key=YOUR_MIGRATE_KEY</code></p>'
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
require __DIR__ . '/migrate.php';
