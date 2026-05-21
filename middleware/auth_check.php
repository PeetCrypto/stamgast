<?php
declare(strict_types=1);

/**
 * Auth Check Middleware
 * Validates session, checks for timeout, and blocks inactive tenants
 */

function authCheck(): void
{
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check session timeout (role-afhankelijk: gast 60 dagen, staff 30 min)
    if (isset($_SESSION['last_activity'])) {
        $role = $_SESSION['role'] ?? '';
        $timeout = ($role === 'guest') ? SESSION_TIMEOUT_GUEST : SESSION_TIMEOUT;
        if ((time() - (int)$_SESSION['last_activity']) > $timeout) {
            // For guests: save slug cookie before destroying session so redirects can still find it
            if ($role === 'guest' && isset($_SESSION['tenant']['slug'])) {
                setGuestRedirectSlugCookie($_SESSION['tenant']['slug']);
            }
            // Session expired - destroy it
            session_unset();
            session_destroy();
            session_start();
        }
    }

    // Update last activity
    if (isLoggedIn()) {
        updateLastActivity();

        // Block access for users belonging to an inactive tenant (superadmins always pass)
        $role = $_SESSION['role'] ?? '';
        if ($role !== 'superadmin') {
            $tenantId = $_SESSION['tenant_id'] ?? null;
            if ($tenantId) {
                require_once __DIR__ . '/../models/Tenant.php';
                $db = \Database::getInstance()->getConnection();
                $tenantModel = new Tenant($db);
                if (!$tenantModel->isActive((int) $tenantId)) {
                    // Tenant is disabled — force logout
                    // For guests: save slug cookie before destroying session
                    if ($role === 'guest' && isset($_SESSION['tenant']['slug'])) {
                        setGuestRedirectSlugCookie($_SESSION['tenant']['slug']);
                    }
                    session_unset();
                    session_destroy();
                    session_start();
                    $_SESSION['flash_error'] = 'Deze locatie is uitgeschakeld door de platformbeheerder. Neem contact op voor meer informatie.';
                    header('Location: ' . getGuestLoginUrl());
                    exit;
                }
            }
        }
    }
}
