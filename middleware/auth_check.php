<?php
declare(strict_types=1);

/**
 * Auth Check Middleware
 * Validates session and checks for timeout
 */

function authCheck(): void
{
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check session timeout
    if (isset($_SESSION['last_activity']) && checkSessionTimeout()) {
        // Session expired - destroy it
        session_unset();
        session_destroy();
        session_start();
    }

    // Update last activity
    if (isLoggedIn()) {
        updateLastActivity();
    }
}
