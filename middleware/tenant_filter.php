<?php
declare(strict_types=1);

/**
 * Tenant Filter Middleware
 * Enforces tenant_id on all data access to ensure multi-tenant isolation
 */

/**
 * Get current tenant ID and ensure it is set
 */
function enforceTenantFilter(): int
{
    $tenantId = currentTenantId();

    if ($tenantId === null) {
        \Response::unauthorized('No tenant context');
    }

    return $tenantId;
}

/**
 * Super-admin bypass: returns null (no tenant filter)
 */
function getTenantFilter(): ?int
{
    $role = currentUserRole();
    if ($role === 'superadmin') {
        // Super-admin can operate without tenant filter
        // or specify a tenant via query param
        $requestedTenant = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT);
        return $requestedTenant ?: null;
    }
    return enforceTenantFilter();
}
