<?php
declare(strict_types=1);

/**
 * POST /api/admin/connect-mollie
 * Server-side Mollie Connect OAuth initiation endpoint for admin (tenant manager).
 *
 * This endpoint is called by the admin (kroegbaas) to start the Mollie Connect
 * OAuth flow for their own tenant. It:
 * 1. Validates the admin session and gets tenant_id from session
 * 2. Generates a CSRF-protected random state
 * 3. Stores state + tenant_id + source in the PHP session
 * 4. Gets the OAuth Client ID from platform_settings
 * 5. Returns the Mollie OAuth authorization URL
 *
 * The admin/settings.php JavaScript then redirects the browser to this URL.
 * After authorization, Mollie redirects to connect-callback.php which
 * validates the state, exchanges the code, and redirects back to /admin/settings.
 *
 * Auth: admin only (enforced by routing middleware)
 * Request: {} (no body needed — tenant_id comes from session)
 * Response: { oauth_url: string }
 */

require_once __DIR__ . '/../../models/PlatformSetting.php';
require_once __DIR__ . '/../../services/MollieService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Get tenant_id from admin session (not from POST body — admin can only connect their own tenant)
$tenantId = currentTenantId();

if (!$tenantId || $tenantId <= 0) {
    Response::error('Geen tenant gevonden in sessie', 'NO_TENANT', 400);
}

// Verify tenant exists
$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);

if (!$tenant) {
    Response::error('Tenant niet gevonden', 'NOT_FOUND', 404);
}

// Get OAuth credentials from platform_settings
$ps = new PlatformSetting($db);
$clientId = $ps->get('mollie_connect_client_id');

if (empty($clientId)) {
    // Fall back to constant
    $clientId = defined('MOLLIE_CONNECT_CLIENT_ID') ? MOLLIE_CONNECT_CLIENT_ID : '';
}

if (empty($clientId)) {
    Response::error(
        'Mollie Connect is nog niet geconfigureerd door het platform. Neem contact op met REGULR.vip.',
        'MISSING_CLIENT_ID',
        422
    );
}

// Generate cryptographically secure random state
$state = bin2hex(random_bytes(32));

// Store state + tenant_id + source in session (one-time use, validated in connect-callback.php)
$_SESSION['mollie_connect_state'] = $state;
$_SESSION['mollie_connect_tenant_id'] = (int) $tenantId;
$_SESSION['mollie_connect_source'] = 'admin';

// Build redirect URI — must EXACTLY match what's registered in Mollie OAuth app settings.
// SECURITY: Use trusted base URL (APP_URL from .env), not X-Forwarded-Host (SSRF risk).
$redirectUri = getTrustedBaseUrl() . '/api/mollie/connect-callback';

// Get the OAuth client secret too (needed by the service for scope consistency)
$clientSecret = $ps->get('mollie_connect_client_secret');
if (empty($clientSecret)) {
    $clientSecret = defined('MOLLIE_CONNECT_CLIENT_SECRET') ? MOLLIE_CONNECT_CLIENT_SECRET : '';
}

// Build Mollie OAuth authorization URL via MollieService (single source of truth
// for scopes — avoids drift between this file and the service class).
$authBuilder = new MollieService('', 'live', $clientId, $clientSecret);
$oauthUrl = $authBuilder->getConnectAuthorizationUrl($redirectUri, $state) . '&force_approval_prompt=true';

error_log("Mollie Connect redirect URI (admin): {$redirectUri}");

// Audit log
$audit = new Audit($db);
$audit->log(
    (int) $tenantId,
    currentUserId(),
    'tenant.mollie_connect_initiated',
    'tenant',
    (int) $tenantId,
    [
        'tenant_name' => $tenant['name'],
        'source'      => 'admin',
        'state'       => substr($state, 0, 8) . '...',
    ]
);

Response::success([
    'oauth_url' => $oauthUrl,
    'tenant_id' => (int) $tenantId,
]);
