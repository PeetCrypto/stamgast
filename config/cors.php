<?php
declare(strict_types=1);

/**
 * CORS Headers Configuration
 * Handles Cross-Origin Resource Sharing for API endpoints
 */

function setCORSHeaders(): void
{
    // In development, allow all origins. In production, restrict to your domain.
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        header('Access-Control-Allow-Origin: *');
    } else {
        // Replace with your actual domain in production
        $allowedOrigins = [
            'https://stamgast.app',
            'https://www.stamgast.app',
        ];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // 24 hours cache

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
