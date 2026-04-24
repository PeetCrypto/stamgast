<?php
declare(strict_types=1);

/**
 * General Helper Functions
 * Utility functions used throughout the STAMGAST platform
 */

/**
 * Sanitize a string for output (prevent XSS)
 */
function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get the client IP address (proxy-aware)
 */
function getClientIP(): string
{
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        $ip = $_SERVER[$header] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
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
 * Require authentication - redirect or die if not logged in
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        if (isApiRequest()) {
            \Response::unauthorized();
        }
        header('Location: /login');
        exit;
    }
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
 * Check session timeout (30 minutes inactivity)
 */
function checkSessionTimeout(): bool
{
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }
    return (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT;
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
