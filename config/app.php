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
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'stamgast_db');
define('DB_USER', getenv('DB_USER') ?: 'u594281888_vip');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// --- SECURITY ---
// Lokaal: huidige pepper (bestaande accounts blijven werken)
// Productie: .env met eigen sterke pepper
define('APP_PEPPER', getenv('APP_PEPPER') ?: 'change-this-to-a-random-string-in-production-32chars-min');
// --- SESSION TIMEOUT ---
// Staff (admin, bartender, superadmin): 8 uur
// Gast (PWA, always-logged-in): 5 jaar (permanent, beveiliging via PIN/FaceID app-lock)
define('SESSION_TIMEOUT', 28800);                   // 8 uur — staff (admin, bartender, superadmin)
define('SESSION_TIMEOUT_GUEST', 157680000);         // 5 jaar — gast PWA (altijd ingelogd)
define('SESSION_COOKIE_LIFETIME_GUEST', 157680000); // 5 jaar — cookie lifetime
define('SESSION_KEEPALIVE_INTERVAL', 900);           // 15 min — SW keepalive ping
define('CSRF_TOKEN_LENGTH', 32);

// --- AUTO-LOCK (frontend) ---
define('APP_LOCK_TIMEOUT_SECONDS', 60);             // 1 minuut achtergrond → lock
define('PIN_MAX_ATTEMPTS', 5);                      // 5 foute PIN pogingen → 1 min cooldown
define('PIN_LOCKOUT_ATTEMPTS', 10);                 // 10 foute pogingen → volledige logout
define('PIN_LENGTH', 4);                            // 4-cijferig

// --- WEBAUTHN ---
define('WEBAUTHN_RP_NAME', 'REGULR.vip');
define('WEBAUTHN_CHALLENGE_TIMEOUT', 300);          // 5 minuten challenge geldigheid
define('WEBAUTHN_USER_VERIFICATION', 'required');   // Forceer FaceID/fingerprint

// --- QR CODE ---
define('QR_EXPIRY_SECONDS', 60);
define('QR_NONCE_LENGTH', 8); // bytes for random_bytes()

// --- POS PAYMENT SESSION ---
define('POS_SESSION_EXPIRY_SECONDS', 300); // 5 minuten geldig

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

// --- Firebase / VAPID ---
// SECURITY: No hardcoded fallback values. All keys must come from .env.
// The Firebase API key is a public key (safe for client-side use), but we still
// avoid hardcoding it to prevent key confusion between environments.
$_firebaseApiKey = getenv('FIREBASE_API_KEY') ?: '';
define('FIREBASE_API_KEY', $_firebaseApiKey);
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'regulr-vip');
define('FIREBASE_MESSAGING_SENDER_ID', getenv('FIREBASE_MESSAGING_SENDER_ID') ?: '584188670460');
define('FIREBASE_APP_ID', getenv('FIREBASE_APP_ID') ?: '');
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: '');
define('VAPID_PRIVATE_KEY_PEM', getenv('VAPID_PRIVATE_KEY_PEM') ?: '');
define('VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:admin@regulr.vip');

// Legacy FCM (deprecated — migrated to VAPID/Web Push)
// FIREBASE_SERVER_KEY was a legacy server key. It has been removed from the codebase
// for security. If absolutely needed, set it via .env: FIREBASE_SERVER_KEY=...
define('FIREBASE_PUBLIC_KEY', VAPID_PUBLIC_KEY);
define('FIREBASE_SERVER_KEY', getenv('FIREBASE_SERVER_KEY') ?: '');

// --- WALLET LIMITS ---
define('DEPOSIT_MIN_CENTS', 10000);  // €100 minimum (consistent met LoyaltyTier::MIN_TOPUP_CENTS)
define('DEPOSIT_MAX_CENTS', 50000);  // 500 euro maximum

// --- BTW TARIEVEN (NL 2025) ---
define('BTW_ALCOHOL_PERCENTAGE', 21); // Alcoholische dranken
define('BTW_FOOD_PERCENTAGE', 9);     // Voeding

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
$scriptDir = rtrim($scriptDir, '/');
// Treat "." (root) as empty string
if ($scriptDir === '.' || $scriptDir === '/') {
    $scriptDir = '';
}
define('BASE_URL', $scriptDir);

// --- FULL BASE URL (scheme + host + path, used in emails and OAuth redirects) ---
// Priority: APP_URL from .env > $_SERVER auto-detect
$_appUrl = getenv('APP_URL');
if (!empty($_appUrl)) {
    // APP_URL is set in .env (e.g. http://stamgast.test or https://app.regulr.vip)
    $_appUrl = rtrim($_appUrl, '/');
    // Append BASE_URL path if APP_URL doesn't already end with it and BASE_URL is not empty
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
// Default fallback UTC (Hostinger shared hosting). TimezoneService::init()
// overrides this per request based on tenant timezone setting.
date_default_timezone_set('UTC');
