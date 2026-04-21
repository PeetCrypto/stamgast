<?php
declare(strict_types=1);

/**
 * Role Check Middleware
 * Validates user role against required roles
 */

/**
 * Check if current user has one of the required roles
 * @param array<string> $allowedRoles
 */
function requireRole(array $allowedRoles): void
{
    $currentRole = currentUserRole();

    if ($currentRole === null) {
        \Response::unauthorized('Not authenticated');
    }

    if (!in_array($currentRole, $allowedRoles, true)) {
        \Response::forbidden('Insufficient permissions');
    }
}

/**
 * Check if user is superadmin
 */
function requireSuperAdmin(): void
{
    requireRole(['superadmin']);
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
