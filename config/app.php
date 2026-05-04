<?php
declare(strict_types=1);

/**
 * Application Configuration
 * Central constants for the REGULR.vip Loyalty Platform
 */

// --- ENVIRONMENT ---
// Lokaal: geen .env → 'development' → APP_DEBUG=true
// Productie: .env met APP_ENV=production → APP_DEBUG=false
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', APP_ENV === 'development');

// --- DATABASE ---
// Lokaal: geen .env → root / stamgast_db / leeg wachtwoord (Laragon defaults)
// Productie: .env met Hostinger credentials
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'stamgast_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// --- SECURITY ---
// Lokaal: huidige pepper (bestaande accounts blijven werken)
// Productie: .env met eigen sterke pepper
define('APP_PEPPER', getenv('APP_PEPPER') ?: 'change-this-to-a-random-string-in-production-32chars-min');
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('CSRF_TOKEN_LENGTH', 32);

// --- QR CODE ---
define('QR_EXPIRY_SECONDS', 60);
define('QR_NONCE_LENGTH', 8); // bytes for random_bytes()

// --- MOLLIE CONNECT (Platform-level) ---
// ⚠️ SECURITY: Platform API key ONLY in server environment, NEVER in database
// Tenant Mollie keys are DEPRECATED — all payments go through Mollie Connect
define('MOLLIE_MODE_DEFAULT', getenv('MOLLIE_MODE_DEFAULT') ?: 'mock');
define('MOLLIE_CONNECT_API_KEY', getenv('MOLLIE_CONNECT_API_KEY') ?: '');
define('MOLLIE_CONNECT_CLIENT_ID', getenv('MOLLIE_CONNECT_CLIENT_ID') ?: '');
define('MOLLIE_CONNECT_CLIENT_SECRET', getenv('MOLLIE_CONNECT_CLIENT_SECRET') ?: '');

// --- PLATFORM FEE ---
define('PLATFORM_FEE_DEFAULT_PERCENTAGE', (float)(getenv('PLATFORM_FEE_DEFAULT_PERCENTAGE') ?: 1.00));
define('PLATFORM_FEE_DEFAULT_MIN_CENTS', (int)(getenv('PLATFORM_FEE_DEFAULT_MIN_CENTS') ?: 25));
define('PLATFORM_FEE_BTW_PERCENTAGE', (float)(getenv('PLATFORM_FEE_BTW_PERCENTAGE') ?: 21.00));

// --- WALLET LIMITS ---
define('DEPOSIT_MIN_CENTS', 500);    // 5 euro minimum
define('DEPOSIT_MAX_CENTS', 50000);  // 500 euro maximum

// --- DISCOUNT LIMITS ---
define('ALCOHOL_DISCOUNT_MAX', 25);  // 25% hard cap (Dutch law)
define('FOOD_DISCOUNT_MAX', 100);    // 100% max

// --- VERIFICATION LIMITS (platform minimums) ---
define('VERIFICATION_SOFT_LIMIT_MIN', 3);    // Absolute minimum soft limit per barman/uur
define('VERIFICATION_HARD_LIMIT_MIN', 5);    // Absolute minimum hard limit per barman/uur
define('VERIFICATION_COOLDOWN_MAX', 600);    // Maximum cooldown: 10 minuten

// --- PAGINATION ---
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// --- BASE URL (auto-detect subdirectory) ---
// Detect if running in a subdirectory (e.g., /stamgast/)
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
define('BASE_URL', rtrim($scriptDir, '/'));

// --- FULL BASE URL (scheme + host + path, used in emails) ---
// Priority: APP_URL from .env > $_SERVER auto-detect
$_appUrl = getenv('APP_URL');
if (!empty($_appUrl)) {
    // APP_URL is set in .env (e.g. http://stamgast.test or https://app.regulr.vip)
    // Append BASE_URL path if APP_URL doesn't already end with it
    $_appUrl = rtrim($_appUrl, '/');
    if (BASE_URL !== '' && !str_ends_with($_appUrl, BASE_URL)) {
        $_appUrl .= BASE_URL;
    }
    define('FULL_BASE_URL', $_appUrl);
} else {
    // Auto-detect from server variables
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('FULL_BASE_URL', $scheme . '://' . $host . BASE_URL);
}

// --- PATHS ---
define('ROOT_PATH', __DIR__ . '/../');
define('PUBLIC_PATH', ROOT_PATH . 'public/');
define('VIEWS_PATH', ROOT_PATH . 'views/');
define('SQL_PATH', ROOT_PATH . 'sql/');

// --- APP INFO ---
define('APP_NAME', 'REGULR.vip');
define('APP_VERSION', '1.0.0-dev');

// --- ERROR REPORTING ---
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// --- TIMEZONE ---
date_default_timezone_set('Europe/Amsterdam');
