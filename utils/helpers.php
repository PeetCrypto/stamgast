<?php
declare(strict_types=1);

/**
 * General Helper Functions
 * Utility functions used throughout the REGULR.vip platform
 */

/**
 * Sanitize a string for output (prevent XSS)
 */
function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get the trusted base URL for external redirects, webhooks, and OAuth callbacks.
 *
 * SECURITY: Never trust X-Forwarded-Host in production. An attacker can spoof
 * this header to perform SSRF, open redirect, or OAuth redirect attacks.
 *
 * In production: always use FULL_BASE_URL (derived from APP_URL in .env).
 * In development: allow X-Forwarded-Host for ngrok/local tunnel support.
 *
 * @return string Base URL without trailing slash (e.g. "https://app.regulr.vip")
 */
function getTrustedBaseUrl(): string
{
    // In development, allow X-Forwarded-Host for ngrok tunnels
    if (APP_DEBUG) {
        $forwardedHost   = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        $forwardedProto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!empty($forwardedHost)) {
            $scheme = !empty($forwardedProto) ? $forwardedProto : 'https';
            // Validate: only allow valid hostnames (no IP injection, no path traversal)
            if (preg_match('/^[a-zA-Z0-9._:-]+$/', $forwardedHost)) {
                return rtrim("{$scheme}://{$forwardedHost}", '/');
            }
        }
    }

    // Production: use FULL_BASE_URL (from APP_URL env var)
    return rtrim(FULL_BASE_URL, '/');
}

/**
 * Get the client IP address.
 *
 * SECURITY: Only trusts X-Forwarded-For in development mode or when behind
 * a known reverse proxy. In production on shared hosting, REMOTE_ADDR is
 * the only trustworthy source. Trusting XFF headers allows IP spoofing
 * which defeats rate limiting, IP whitelists, and audit logging.
 *
 * @return string Client IP address
 */
function getClientIP(): string
{
    // In development, allow X-Forwarded-For for ngrok/local proxy testing
    if (APP_DEBUG) {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            $ip = $_SERVER[$header] ?? '';
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    // Production: only trust REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Generate a CSRF token and store it in the session
 */
function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token
 */
function validateCSRFToken(string $token): bool
{
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Format cents to euro string
 */
function centsToEuro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.');
}

/**
 * Parse euro string to cents
 */
function euroToCents(string $euro): int
{
    $cleaned = str_replace(['.', ','], '', $euro);
    return (int) $cleaned;
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

/**
 * Get current user ID from session
 */
function currentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current tenant ID from session
 */
function currentTenantId(): ?int
{
    return $_SESSION['tenant_id'] ?? null;
}

/**
 * Get current user role from session
 */
function currentUserRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

/**
 * Get the effective role for access control.
 * When a superadmin is "viewing as" another role (e.g. admin),
 * returns that role. Otherwise returns the actual session role.
 */
function effectiveRole(): ?string
{
    if (isset($_SESSION['viewing_as']['role']) && currentUserRole() === 'superadmin') {
        return $_SESSION['viewing_as']['role'];
    }
    return currentUserRole();
}

/**
 * Check if the superadmin is currently in "view as" mode
 */
function isViewingAs(): bool
{
    return isset($_SESSION['viewing_as']['role']) && currentUserRole() === 'superadmin';
}

/**
 * Get the role being impersonated (viewing as).
 * Returns null if not in viewing_as mode.
 */
function viewingAsRole(): ?string
{
    return $_SESSION['viewing_as']['role'] ?? null;
}

/**
 * Require authentication - redirect or die if not logged in
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        if (isApiRequest()) {
            \Response::unauthorized();
        }
        header('Location: ' . getGuestLoginUrl());
        exit;
    }
}

/**
 * Get the login URL for the current user role.
 * For guests: returns /j/{slug} (from session or cookie fallback).
 * For others: returns /login.
 */
function getGuestLoginUrl(): string
{
    $slug = null;

    // 1. Try session tenant (user was logged in, e.g. logout/timeout)
    if (isset($_SESSION['tenant']['slug'])) {
        $slug = $_SESSION['tenant']['slug'];
    }

    // 2. Fallback: cookie set by auth_check before session destroy
    if ($slug === null && isset($_COOKIE['guest_redirect_slug'])) {
        $slug = $_COOKIE['guest_redirect_slug'];
        // Clear the cookie so it's not reused
        setcookie('guest_redirect_slug', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !APP_DEBUG,
            'httponly'  => true,
            'samesite'  => 'Strict',
        ]);
        unset($_COOKIE['guest_redirect_slug']);
    }

    if ($slug && is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,98}[a-z0-9]$/', $slug)) {
        return '/j/' . $slug;
    }

    return '/login';
}

/**
 * Set a short-lived cookie with the guest tenant slug.
 * Used by auth_check before session destroy so redirects can still find the slug.
 */
function setGuestRedirectSlugCookie(string $slug): void
{
    setcookie('guest_redirect_slug', $slug, [
        'expires'  => time() + 60, // Only valid for 60 seconds
        'path'     => '/',
        'secure'   => !APP_DEBUG,
        'httponly'  => true,
        'samesite'  => 'Strict',
    ]);
}

/**
 * Check if the current request is an API call
 */
function isApiRequest(): bool
{
    return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
}

/**
 * Redirect to a URL
 */
function redirect(string $url): void
{
    header('Location: ' . BASE_URL . $url);
    exit;
}

/**
 * Generate a UUID v4
 */
function generateUUID(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Regenerate session ID safely (anti-session-fixation)
 */
function regenerateSession(): void
{
    $old = $_SESSION;
    session_regenerate_id(true);
    $_SESSION = array_merge($_SESSION, $old);
}

/**
 * Update last activity timestamp
 */
function updateLastActivity(): void
{
    $_SESSION['last_activity'] = time();
}

/**
 * Check session timeout (role-afhankelijk: gast 60 dagen, staff 30 min)
 */
function checkSessionTimeout(): bool
{
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }
    $role = $_SESSION['role'] ?? '';
    $timeout = ($role === 'guest') ? SESSION_TIMEOUT_GUEST : SESSION_TIMEOUT;
    return (time() - (int)$_SESSION['last_activity']) > $timeout;
}

/**
 * Get JSON input from request body
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

/**
 * Validate email format
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Check if user is at least 18 years old
 */
function isAdult(string $birthdate): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$date) return false;
    $today = new DateTime();
    $age = $today->diff($date)->y;
    return $age >= 18;
}
