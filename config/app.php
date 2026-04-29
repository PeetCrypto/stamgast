<?php
declare(strict_types=1);

/**
 * Application Configuration
 * Central constants for the REGULR.vip Loyalty Platform
 */

// --- ENVIRONMENT ---
define('APP_ENV', 'development'); // 'development' | 'production'
define('APP_DEBUG', APP_ENV === 'development');

// --- DATABASE ---
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'stamgast_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --- SECURITY ---
define('APP_PEPPER', 'change-this-to-a-random-string-in-production-32chars-min');
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('CSRF_TOKEN_LENGTH', 32);

// --- QR CODE ---
define('QR_EXPIRY_SECONDS', 60);
define('QR_NONCE_LENGTH', 8); // bytes for random_bytes()

// --- MOLLIE CONNECT (Platform-level) ---
// ⚠️ SECURITY: Platform API key ONLY in server environment, NEVER in database
// Tenant Mollie keys are DEPRECATED — all payments go through Mollie Connect
define('MOLLIE_MODE_DEFAULT', 'mock'); // 'mock' | 'test' | 'live'
define('MOLLIE_CONNECT_API_KEY', '');  // Platform Mollie API key (set via .env in production)
define('MOLLIE_CONNECT_CLIENT_ID', ''); // Mollie Connect OAuth client ID
define('MOLLIE_CONNECT_CLIENT_SECRET', ''); // Mollie Connect OAuth client secret

// --- PLATFORM FEE ---
define('PLATFORM_FEE_DEFAULT_PERCENTAGE', 1.00); // Default 1%
define('PLATFORM_FEE_DEFAULT_MIN_CENTS', 25);    // Default €0,25 minimum
define('PLATFORM_FEE_BTW_PERCENTAGE', 21.00);    // 21% BTW over platform fee (Nederland)

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
