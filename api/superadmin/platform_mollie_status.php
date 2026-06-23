<?php
declare(strict_types=1);

/**
 * GET /api/superadmin/platform-mollie-status
 *
 * Returns the status of the platform Mollie API key.
 *
 * Since the API key is profile-scoped, we cannot use /profiles or
 * /onboarding/me (those require organization-level OAuth tokens).
 * Instead we validate the key via /methods and check the key format.
 *
 * Auth: superadmin only
 */

require_once __DIR__ . '/../../services/MollieService.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$db = Database::getInstance()->getConnection();
$ps = new PlatformSetting($db);

$apiKey = $ps->get('mollie_connect_api_key') ?? '';
$clientId = $ps->get('mollie_connect_client_id') ?? '';
$clientSecret = $ps->get('mollie_connect_client_secret') ?? '';

$result = [
    'configured'  => false,
    'key_type'    => 'unknown',
    'key_valid'   => null,
    'onboarding'  => null,
    'profiles'   => [],
    'error'       => null,
    'checked_at'  => null,
];

if (empty($apiKey) || empty($clientId) || empty($clientSecret)) {
    $result['error'] = 'Platform Mollie Connect is niet volledig geconfigureerd. Vul API Key, Client ID en Client Secret in.';
    Response::success($result);
    exit;
}

$result['configured'] = true;

if (str_starts_with($apiKey, 'live_')) {
    $result['key_type'] = 'live';
} elseif (str_starts_with($apiKey, 'test_')) {
    $result['key_type'] = 'test';
}

$mode = ($result['key_type'] === 'test') ? 'test' : 'live';

// Validate the API key using /methods endpoint — works with any API key
// (profile-scoped or not) since it only reads public method data.
$mollie = new MollieService($apiKey, $mode, $clientId, $clientSecret);

try {
    // /methods is the simplest endpoint that works with profile-scoped keys
    $methods = $mollie->listMethods();
    $result['key_valid'] = true;
    $result['checked_at'] = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    // Note: /profiles and /onboarding/me require organization-level OAuth tokens.
    // The platform API key is profile-scoped, so these endpoints return 403.
    // This is expected and not an error — it just means we can't check onboarding
    // status with an API key. The superadmin should check onboarding manually
    // in the Mollie Dashboard.
    $result['onboarding'] = [
        'status'                  => 'n.v.t. (API key is profiel-scoped)',
        'can_receive_payments'    => null,
        'can_receive_settlements' => null,
    ];

} catch (\RuntimeException $e) {
    $result['key_valid'] = false;
    $result['error'] = $e->getMessage();
} catch (\Throwable $e) {
    $result['key_valid'] = false;
    $result['error'] = 'Onverwachte fout: ' . $e->getMessage();
}

Response::success($result);
