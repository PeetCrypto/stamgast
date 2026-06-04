<?php
declare(strict_types=1);

/**
 * Role Check Middleware
 * Validates user role against required roles
 * Supports "View As" impersonation for superadmins
 */

/**
 * Check if current user has one of the required roles.
 * Uses effectiveRole() so that superadmins in "view as" mode
 * get the permissions of the impersonated role.
 * @param array<string> $allowedRoles
 */
function requireRole(array $allowedRoles): void
{
    $currentRole = effectiveRole();

    if ($currentRole === null) {
        \Response::unauthorized('Not authenticated');
    }

    if (!in_array($currentRole, $allowedRoles, true)) {
        \Response::forbidden('Insufficient permissions');
    }
}

/**
 * Check if user is superadmin.
 * Always checks the ACTUAL role (not effectiveRole) so that
 * superadmin-only endpoints remain accessible even in "view as" mode,
 * and impersonated roles can never reach superadmin endpoints.
 */
function requireSuperAdmin(): void
{
    $actualRole = currentUserRole();

    if ($actualRole === null) {
        \Response::unauthorized('Not authenticated');
    }

    if ($actualRole !== 'superadmin') {
        \Response::forbidden('Insufficient permissions');
    }
}

/**
 * Check if user is admin or higher
 */
function requireAdmin(): void
{
    requireRole(['superadmin', 'admin']);
}

/**
 * Check if user is bartender or higher
 */
function requireBartender(): void
{
    requireRole(['superadmin', 'admin', 'bartender']);
}

/**
 * Check if user is any authenticated user (guest+)
 */
function requireAuthenticated(): void
{
    requireRole(['superadmin', 'admin', 'bartender', 'guest']);
}
