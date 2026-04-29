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
 * 1. Superadmin clicks "Connect Mollie" → stores CSRF state in session, redirects to Mollie
 * 2. Tenant authorizes on Mollie
 * 3. Mollie redirects here with ?code=XXX&state=YYY
 * 4. We validate state, exchange code for token, save org ID to tenant
 * 5. Redirect back to superadmin tenant detail page
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

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $redirectUri = "{$scheme}://{$host}" . BASE_URL . "/api/mollie/connect-callback";

    $tokenData = $mollie->exchangeConnectCode($code, $redirectUri);

    $organizationId = $tokenData['organization_id'] ?? '';

    if (empty($organizationId)) {
        throw new RuntimeException('Geen organization ID ontvangen van Mollie');
    }

    // ── Update tenant ───────────────────────────────────────────────────────

    $tenantModel->update($sessionTenantId, [
        'mollie_connect_id'     => $organizationId,
        'mollie_connect_status' => 'active',
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
            'organization_id' => $organizationId,
            'tenant_name'     => $tenant['name'],
        ]
    );

    // ── Redirect back to tenant detail ──────────────────────────────────────

    $redirectUrl = BASE_URL . '/superadmin/tenant/' . $sessionTenantId . '?connect=success';
    header('Location: ' . $redirectUrl);
    exit;

} catch (Throwable $e) {
    // Clear state from session
    unset($_SESSION['mollie_connect_tenant_id']);

    $safeMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    http_response_code(500);
    echo "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;'>
          <h1>Koppeling mislukt</h1>
          <p>Er is een fout opgetreden bij het koppelen van Mollie:</p>
          <p><code>{$safeMessage}</code></p>
          <p><a href='" . BASE_URL . "/superadmin/tenant/{$sessionTenantId}'>Terug naar tenant</a></p>
          </body></html>";
    exit;
}
