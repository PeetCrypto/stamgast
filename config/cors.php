<?php
declare(strict_types=1);

/**
 * CORS Headers Configuration
 * Handles Cross-Origin Resource Sharing for API endpoints
 */

function setCORSHeaders(): void
{
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        // LOKAAL: Allow all origins
        header('Access-Control-Allow-Origin: *');
    } else {
        // PRODUCTIE: Alleen het eigenlijke domein
        // Auto-detect vanuit de huidige request (werkt op elke server)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';

        $allowedHosts = [$host];
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (in_array($originHost, $allowedHosts, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
