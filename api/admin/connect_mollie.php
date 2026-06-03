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

// Build redirect URI (must match what's registered in Mollie OAuth app settings)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['stamgast.test', 'app.regulr.vip']));
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$redirectUri = "{$scheme}://{$host}" . BASE_URL . "/api/mollie/connect-callback";

// Build Mollie OAuth authorization URL
$oauthParams = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'organizations.read payments.write profiles.read',
    'state'         => $state,
]);

$oauthUrl = 'https://my.mollie.com/oauth2/authorize?' . $oauthParams;

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
