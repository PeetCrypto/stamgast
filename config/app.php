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

// --- MOLLIE (defaults, per-tenant override) ---
define('MOLLIE_MODE_DEFAULT', 'mock'); // 'mock' | 'test' | 'live'

// --- WALLET LIMITS ---
define('DEPOSIT_MIN_CENTS', 500);    // 5 euro minimum
define('DEPOSIT_MAX_CENTS', 50000);  // 500 euro maximum

// --- DISCOUNT LIMITS ---
define('ALCOHOL_DISCOUNT_MAX', 25);  // 25% hard cap (Dutch law)
define('FOOD_DISCOUNT_MAX', 100);    // 100% max

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
