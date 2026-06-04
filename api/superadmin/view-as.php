<?php
declare(strict_types=1);

/**
 * POST /api/superadmin/view-as
 * Start "View As Admin" impersonation for a specific tenant.
 * Saves original session state and overrides with tenant context.
 *
 * DELETE /api/superadmin/view-as
 * Stop impersonation and restore original superadmin session.
 *
 * Security: Only accessible by actual superadmin (checked by router via requireSuperAdmin).
 */

require_once __DIR__ . '/../../models/Tenant.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // ── Start impersonation ──
    $input = getJsonInput();
    $tenantId = isset($input['tenant_id']) ? (int) $input['tenant_id'] : 0;

    if ($tenantId <= 0) {
        Response::error('tenant_id is verplicht', 'MISSING_TENANT_ID', 400);
    }

    // Load tenant
    $db = Database::getInstance()->getConnection();
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);

    if (!$tenant) {
        Response::error('Tenant niet gevonden', 'TENANT_NOT_FOUND', 404);
    }

    // Already viewing_as? Stop previous impersonation first (no nesting)
    if (isset($_SESSION['viewing_as'])) {
        // Restore previous state before starting new one
        restoreOriginalSession();
    }

    // Save original session state that we're about to override
    $_SESSION['viewing_as'] = [
        'role'                    => 'admin',
        'tenant_id'               => $tenantId,
        'saved_tenant_id'         => $_SESSION['tenant_id'] ?? null,
        'saved_tenant_name'       => $_SESSION['tenant_name'] ?? null,
        'saved_brand_color'       => $_SESSION['brand_color'] ?? null,
        'saved_secondary_color'   => $_SESSION['secondary_color'] ?? null,
        'saved_tenant_logo'       => $_SESSION['tenant_logo'] ?? null,
        'saved_tenant'            => $_SESSION['tenant'] ?? null,
        'saved_whitelisted_ips'   => $_SESSION['whitelisted_ips'] ?? null,
    ];

    // Override session vars with impersonated tenant context
    $_SESSION['tenant_id']       = (int) $tenant['id'];
    $_SESSION['tenant_name']     = $tenant['name'];
    $_SESSION['brand_color']     = $tenant['brand_color'] ?? '#FFC107';
    $_SESSION['secondary_color'] = $tenant['secondary_color'] ?? '#FF9800';
    $_SESSION['tenant_logo']     = $tenant['logo_path'] ?? '';
    $_SESSION['tenant']          = $tenant;
    $_SESSION['whitelisted_ips'] = $tenant['whitelisted_ips'] ?? null;

    Response::success([
        'viewing_as' => 'admin',
        'tenant_id'  => $tenantId,
        'tenant_name' => $tenant['name'],
    ]);

} elseif ($method === 'DELETE') {
    // ── Stop impersonation ──
    if (!isset($_SESSION['viewing_as'])) {
        Response::error('Niet in view-as modus', 'NOT_VIEWING_AS', 400);
    }

    $tenantName = $_SESSION['tenant_name'] ?? '';

    restoreOriginalSession();

    Response::success([
        'stopped'     => true,
        'tenant_name' => $tenantName,
    ]);

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

/**
 * Restore original superadmin session state from viewing_as backup
 */
function restoreOriginalSession(): void
{
    $saved = $_SESSION['viewing_as'] ?? [];

    // Restore original values (may be null for superadmin)
    $_SESSION['tenant_id']       = $saved['saved_tenant_id'] ?? null;
    $_SESSION['tenant_name']     = $saved['saved_tenant_name'] ?? (defined('APP_NAME') ? APP_NAME : 'REGULR.vip');
    $_SESSION['brand_color']     = $saved['saved_brand_color'] ?? '#FFC107';
    $_SESSION['secondary_color'] = $saved['saved_secondary_color'] ?? '#FF9800';
    $_SESSION['tenant_logo']     = $saved['saved_tenant_logo'] ?? '';
    $_SESSION['tenant']          = $saved['saved_tenant'] ?? null;
    $_SESSION['whitelisted_ips'] = $saved['saved_whitelisted_ips'] ?? null;

    // Remove viewing_as state
    unset($_SESSION['viewing_as']);
}
