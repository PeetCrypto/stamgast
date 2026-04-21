<?php
declare(strict_types=1);

/**
 * CSRF Middleware
 * Validates CSRF token on state-changing requests (POST/PUT/DELETE)
 */

function csrfCheck(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Only check state-changing methods
    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        return;
    }

    // Skip for API calls that use session-based auth (session IS the CSRF protection)
    // But enforce for form submissions
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        // JSON API calls: check X-CSRF-Token header
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!validateCSRFToken($token)) {
            \Response::forbidden('CSRF token mismatch');
        }
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded') ||
              str_contains($contentType, 'multipart/form-data')) {
        // Form submissions: check _csrf_token field
        $token = $_POST['_csrf_token'] ?? '';
        if (!validateCSRFToken($token)) {
            \Response::forbidden('CSRF token mismatch');
        }
    }
}
