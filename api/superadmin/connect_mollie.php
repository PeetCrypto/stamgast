<?php
declare(strict_types=1);

/**
 * POST /api/superadmin/connect-mollie
 * Server-side Mollie Connect OAuth initiation endpoint.
 *
 * This endpoint is called by the superadmin to start the Mollie Connect
 * OAuth flow for a specific tenant. It:
 * 1. Validates the superadmin session
 * 2. Generates a CSRF-protected random state
 * 3. Stores state + tenant_id in the PHP session
 * 4. Gets the OAuth Client ID from platform_settings
 * 5. Returns the Mollie OAuth authorization URL
 *
 * The tenant_detail.php JavaScript then redirects the browser to this URL.
 * After authorization, Mollie redirects to connect-callback.php which
 * validates the state and exchanges the code for an access token.
 *
 * Auth: superadmin only
 * Request: { tenant_id: int }
 * Response: { oauth_url: string }
 */

require_once __DIR__ . '/../../models/PlatformSetting.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$tenantId = (int) ($input['tenant_id'] ?? 0);

if ($tenantId <= 0) {
    Response::error('tenant_id is verplicht', 'MISSING_FIELD', 400);
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
        'Mollie Connect Client ID niet geconfigureerd. Configureer via Superadmin > Platform Instellingen.',
        'MISSING_CLIENT_ID',
        422
    );
}

// Generate cryptographically secure random state
$state = bin2hex(random_bytes(32));

// Store state + tenant_id in session (one-time use, validated in connect-callback.php)
$_SESSION['mollie_connect_state'] = $state;
$_SESSION['mollie_connect_tenant_id'] = $tenantId;

// Build redirect URI — must EXACTLY match what's registered in Mollie OAuth app settings.
// Use the ACTUAL request host (supports ngrok proxy) instead of APP_URL from .env.
$forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
if (!empty($forwardedHost)) {
    // Behind a proxy (ngrok, Cloudflare, etc.) — use forwarded headers
    $redirectScheme = !empty($forwardedProto) ? $forwardedProto : 'https';
    $redirectUri = $redirectScheme . '://' . $forwardedHost . BASE_URL . '/api/mollie/connect-callback';
} else {
    // Direct access — use FULL_BASE_URL (APP_URL or auto-detected)
    $redirectUri = FULL_BASE_URL . '/api/mollie/connect-callback';
}

error_log("Mollie Connect redirect URI: {$redirectUri}");

// Build Mollie OAuth authorization URL
$oauthParams = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'organizations.read payments.read payments.write profiles.read',
    'state'         => $state,
    'force_approval_prompt' => 'true',
]);

$oauthUrl = 'https://my.mollie.com/oauth2/authorize?' . $oauthParams;

// Audit log
$audit = new Audit($db);
$audit->log(
    0,
    currentUserId(),
    'tenant.mollie_connect_initiated',
    'tenant',
    $tenantId,
    [
        'tenant_name' => $tenant['name'],
        'state'       => substr($state, 0, 8) . '...', // Don't log full state
    ]
);

Response::success([
    'oauth_url' => $oauthUrl,
    'tenant_id' => $tenantId,
    'debug_redirect_uri' => $redirectUri, // For debugging - remove in production
]);
