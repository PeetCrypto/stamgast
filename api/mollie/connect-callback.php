<?php
declare(strict_types=1);

/**
 * Mollie Connect OAuth Callback
 * GET /api/mollie/connect-callback?code=XXX&state=YYY
 *
 * Called by Mollie after the tenant authorizes the platform app.
 * No auth required — this is a redirect target from Mollie's OAuth flow.
 *
 * Flow:
 * 1. Admin or Superadmin clicks "Connect Mollie" → stores CSRF state + source in session, redirects to Mollie
 * 2. Tenant authorizes on Mollie
 * 3. Mollie redirects here with ?code=XXX&state=YYY
 * 4. We validate state, exchange code for token, save org ID to tenant
 * 5. Redirect back to admin settings or superadmin tenant detail page (based on source)
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/helpers.php';
require_once __DIR__ . '/../../services/MollieService.php';

// Start session (needed for state validation)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$errorDescription = $_GET['error_description'] ?? '';

// ── Handle error from Mollie (user denied access) ──────────────────────────

if (!empty($error)) {
    $safeError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    $safeDesc = htmlspecialchars($errorDescription, ENT_QUOTES, 'UTF-8');
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Koppeling mislukt</h1>
          <p>Mollie fout: {$safeError}</p>
          <p>{$safeDesc}</p>
          <p><a href='" . BASE_URL . "/superadmin'>Terug naar dashboard</a></p>
          </body></html>";
    exit;
}

// ── Validate required parameters ────────────────────────────────────────────

if (empty($code) || empty($state)) {
    http_response_code(400);
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Ongeldige aanvraag</h1>
          <p>Ontbrekende code of state parameter.</p>
          <p><a href='" . BASE_URL . "/superadmin'>Terug naar dashboard</a></p>
          </body></html>";
    exit;
}

// ── CSRF State validation ───────────────────────────────────────────────────

$sessionState = $_SESSION['mollie_connect_state'] ?? '';
$sessionTenantId = (int) ($_SESSION['mollie_connect_tenant_id'] ?? 0);

if (empty($sessionState) || !hash_equals($sessionState, $state)) {
    // Clear state from session
    unset($_SESSION['mollie_connect_state'], $_SESSION['mollie_connect_tenant_id']);

    http_response_code(400);
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Ongeldige state</h1>
          <p>De beveiligingstoken komt niet overeen. Probeer opnieuw.</p>
          <p><a href='" . BASE_URL . "/superadmin'>Terug naar dashboard</a></p>
          </body></html>";
    exit;
}

// State is valid — clear it (one-time use)
unset($_SESSION['mollie_connect_state']);

// ── Validate tenant ─────────────────────────────────────────────────────────

if ($sessionTenantId <= 0) {
    http_response_code(400);
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Geen tenant gevonden</h1>
          <p>De sessie is verlopen. Probeer opnieuw vanuit het tenant beheer.</p>
          <p><a href='" . BASE_URL . "/superadmin'>Terug naar dashboard</a></p>
          </body></html></html>";
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $tenantModel = new Tenant($db);

    $tenant = $tenantModel->findById($sessionTenantId);
    if (!$tenant) {
        throw new RuntimeException('Tenant niet gevonden (ID: ' . $sessionTenantId . ')');
    }

    // ── Exchange code for token ─────────────────────────────────────────────

    require_once __DIR__ . '/../../models/PlatformSetting.php';
    $ps = new PlatformSetting($db);
    $mollie = new MollieService(
        $ps->get('mollie_connect_api_key') ?? '',
        'live',
        $ps->get('mollie_connect_client_id'),
        $ps->get('mollie_connect_client_secret')
    );

    // Build redirect URI (must match what's registered in Mollie OAuth app settings)
// Check for HTTPS in multiple ways (Laragon may set it differently)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['stamgast.test', 'app.regulr.vip']));
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$redirectUri = "{$scheme}://{$host}" . BASE_URL . "/api/mollie/connect-callback";

    $tokenData = $mollie->exchangeConnectCode($code, $redirectUri);

    $organizationId = $tokenData['organization_id'] ?? '';
    $accessToken    = $tokenData['access_token'] ?? '';

    // Debug: log the full token response
    error_log("Mollie OAuth token response: " . json_encode($tokenData));

    // ⚠️ organization_id is REQUIRED for Mollie Connect payments (onBehalfOf).
    // This happens when the OAuth app is authorized with the SAME Mollie account
    // that owns the OAuth app. Each tenant MUST have their own separate Mollie account.
    // Without a valid org ID, payment creation will fail (Mollie rejects onBehalfOf).
    if (empty($organizationId)) {
        throw new RuntimeException(
            'Mollie Connect: geen organization ID ontvangen van Mollie. ' .
            'Dit gebeurt als de OAuth app wordt geautoriseerd met hetzelfde Mollie account ' .
            'dat de OAuth app bezit. Elke tenant moet een eigen, apart Mollie account hebben. ' .
            'Gebruik een ander Mollie account om de koppeling te maken.'
        );
    }
    $orgId = $organizationId;

    // ── Fetch website profile ID from Mollie ────────────────────────────────
    // Required for payment creation — Mollie returns 422 without a profileId.
    // Uses /v2/profiles (list) because /v2/profiles/me doesn't work with OAuth tokens.
    $profileId = '';

    $ch = curl_init('https://api.mollie.com/v2/profiles');
    if ($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);

        $profileResponse = curl_exec($ch);
        $profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($profileResponse !== false && $profileHttpCode < 400) {
            $profileData = json_decode($profileResponse, true);
            $profiles = $profileData['_embedded']['profiles'] ?? [];
            // Use the first active profile
            foreach ($profiles as $profile) {
                if (($profile['status'] ?? '') === 'active') {
                    $profileId = $profile['id'] ?? '';
                    break;
                }
            }
            // Fallback: use first profile regardless of status
            if (empty($profileId) && !empty($profiles)) {
                $profileId = $profiles[0]['id'] ?? '';
            }
        } else {
            error_log("Mollie Connect: profile list failed (HTTP {$profileHttpCode}): " . substr($profileResponse ?: '', 0, 200));
        }
    }

    if (empty($profileId)) {
        error_log("Mollie Connect: could not fetch profile ID for org {$orgId}. Payment creation will fail.");
    }

    // ── Update tenant ───────────────────────────────────────────────────────

    $tenantModel->update($sessionTenantId, [
        'mollie_connect_id'            => $orgId,
        'mollie_connect_status'        => 'active',
        'mollie_connect_access_token'  => $accessToken,
        'mollie_connect_profile_id'    => $profileId,
    ]);

    // Clear tenant ID from session
    unset($_SESSION['mollie_connect_tenant_id']);

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        0,
        $_SESSION['user_id'] ?? 0,
        'tenant.mollie_connected',
        'tenant',
        $sessionTenantId,
        [
            'organization_id' => $orgId,
            'tenant_name'     => $tenant['name'],
        ]
    );

    // ── Redirect back based on source (admin or superadmin) ─────────────────

    $source = $_SESSION['mollie_connect_source'] ?? 'superadmin';
    unset($_SESSION['mollie_connect_source']);

    if ($source === 'admin') {
        $redirectUrl = BASE_URL . '/admin/settings?connect=success';
    } else {
        $redirectUrl = BASE_URL . '/superadmin/tenant/' . $sessionTenantId . '?connect=success';
    }
    header('Location: ' . $redirectUrl);
    exit;

} catch (Throwable $e) {
    // Read source BEFORE clearing from session
    $source = $_SESSION['mollie_connect_source'] ?? 'superadmin';

    // Clear state from session
    unset($_SESSION['mollie_connect_tenant_id'], $_SESSION['mollie_connect_source']);

    $safeMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $backLink = ($source === 'admin')
        ? BASE_URL . '/admin/settings'
        : BASE_URL . '/superadmin/tenant/' . $sessionTenantId;

    http_response_code(500);
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Koppeling mislukt</h1>
          <p>Er is een fout opgetreden bij het koppelen van Mollie:</p>
          <p><code>{$safeMessage}</code></p>
          <p><a href='" . $backLink . "'>Terug naar instellingen</a></p>
          </body></html>";
    exit;
}
