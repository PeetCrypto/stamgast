<?php
declare(strict_types=1);

/**
 * GET /api/superadmin/mollie-status?tenant_id=123
 *
 * Returns the live Mollie onboarding status of a connected tenant.
 * This lets the superadmin see whether the tenant is ready for live
 * payments (canReceivePayments) without guessing.
 *
 * Auth: superadmin only
 * Response: {
 *   connected: bool,
 *   mode: string,                 // current mollie_status (mock/test/live)
 *   tenant_onboarding: {...},     // tenant onboarding status
 *   platform_onboarding: {...},   // PLATFORM (superadmin) onboarding status
 *   profiles: array,              // website profiles (id, mode, status)
 *   error: string|null
 * }
 */

require_once __DIR__ . '/../../services/MollieService.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$tenantId = (int) ($_GET['tenant_id'] ?? 0);

if ($tenantId <= 0) {
    Response::error('tenant_id is verplicht', 'MISSING_FIELD', 400);
}

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);

if (!$tenant) {
    Response::error('Tenant niet gevonden', 'NOT_FOUND', 404);
}

$mode = $tenant['mollie_status'] ?? 'mock';
$accessToken = $tenant['mollie_connect_access_token'] ?? '';
$connected = ($tenant['mollie_connect_status'] ?? 'none') === 'active' && !empty($accessToken);

$result = [
    'connected'           => $connected,
    'mode'                => $mode,
    'tenant_onboarding'   => null,
    'platform_onboarding' => null,
    'profiles'            => [],
    'cached'              => [
        'onboarding_status'    => $tenant['mollie_connect_onboarding_status'] ?? null,
        'can_receive_payments' => isset($tenant['mollie_connect_can_receive_payments'])
            ? (bool) $tenant['mollie_connect_can_receive_payments'] : null,
        'checked_at'           => $tenant['mollie_connect_status_checked_at'] ?? null,
    ],
    'error'               => null,
];

// ── 1) TENANT onboarding status ────────────────────────────────────────
if (!$connected) {
    $result['error'] = 'Tenant heeft geen actieve Mollie Connect koppeling.';
} else {
    // Proactive token refresh — Mollie access tokens expire after 1 hour.
    // Mirrors WalletService::createDeposit() refresh logic.
    $tenantRefreshToken = $tenant['mollie_connect_refresh_token'] ?? '';
    if (!empty($tenantRefreshToken) && $tenantModel->isMollieTokenExpired($tenantId)) {
        try {
            $ps = new PlatformSetting($db);
            $refresher = new MollieService(
                '', 'live',
                $ps->get('mollie_connect_client_id'),
                $ps->get('mollie_connect_client_secret')
            );
            $newTokens = $refresher->refreshAccessToken($tenantRefreshToken);

            $tenantModel->updateMollieTokens(
                $tenantId,
                $newTokens['access_token'],
                $newTokens['refresh_token'],
                $newTokens['expires_at']
            );

            $accessToken = $newTokens['access_token'];
        } catch (\Throwable $e) {
            error_log("Mollie token refresh failed (mollie_status) for tenant {$tenantId}: " . $e->getMessage());
            // Continue — the call below may fail with a clear error, or the
            // (now expired) token may still be accepted briefly.
        }
    }

    try {
        $mollie = new MollieService($accessToken, 'live');

        // ── Primary source: profiles (scope profiles.read is always granted) ──
        // We derive the account's live-readiness from the website profiles,
        // NOT from the onboarding endpoint. This avoids requiring the extra
        // onboarding.read scope, which would force existing connected tenants
        // to re-authorize. A profile with mode=live AND status=verified means
        // the account can receive live payments.
        $profiles = [];
        try {
            $profiles = $mollie->listProfiles();
            $result['profiles'] = $profiles;
        } catch (\Throwable $e) {
            // profiles.read should always work; log if it doesn't
            error_log("Mollie profiles fetch failed for tenant {$tenantId}: " . $e->getMessage());
        }

        // Derive live-readiness from profiles.
        $hasVerifiedLiveProfile = false;
        $hasBlockedProfile = false;
        $hasLiveProfile = false;
        foreach ($profiles as $p) {
            if (($p['mode'] ?? '') === 'live') {
                $hasLiveProfile = true;
                if (($p['status'] ?? '') === 'verified') {
                    $hasVerifiedLiveProfile = true;
                }
                if (($p['status'] ?? '') === 'blocked') {
                    $hasBlockedProfile = true;
                }
            }
        }

        // Map to onboarding-equivalent status for display compatibility.
        if ($hasBlockedProfile) {
            $derivedStatus = 'blocked';
            $canReceive = false;
        } elseif ($hasVerifiedLiveProfile) {
            $derivedStatus = 'completed';
            $canReceive = true;
        } elseif ($hasLiveProfile) {
            // Live profile exists but not verified yet → onboarding in progress.
            $derivedStatus = 'needs-data';
            $canReceive = false;
        } else {
            // No live profile at all.
            $derivedStatus = 'needs-data';
            $canReceive = false;
        }

        // ── Optional enrichment: onboarding endpoint (best-effort) ──────────
        // If the onboarding.read scope happens to be granted, the real onboarding
        // status is more precise (needs-data/in-review/completed). If not, we
        // silently ignore the permission error and use the profiles-derived value.
        $onboardingSource = 'profiles';
        try {
            $onboarding = $mollie->getOnboardingStatus();
            $result['tenant_onboarding'] = $onboarding;
            // Onboarding endpoint is authoritative when available.
            $derivedStatus = $onboarding['status'] ?? $derivedStatus;
            $canReceive = (bool) ($onboarding['can_receive_payments'] ?? $canReceive);
            $onboardingSource = 'onboarding';
        } catch (\Throwable $e) {
            // Expected for connections that lack the onboarding.read scope.
            // This is NOT an error — we fall back to the profiles-derived status.
            $result['tenant_onboarding'] = null;
            error_log("Mollie onboarding check skipped for tenant {$tenantId} (using profiles fallback): " . $e->getMessage());
        }

        $result['account_status'] = [
            'status'               => $derivedStatus,
            'can_receive_payments' => $canReceive,
            'source'               => $onboardingSource, // 'onboarding' or 'profiles'
        ];

        // Persist the derived status to the cache columns so the superadmin
        // tenant detail page can render it instantly without hitting the API.
        try {
            $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "UPDATE `tenants`
                 SET `mollie_connect_onboarding_status`   = :status,
                     `mollie_connect_can_receive_payments` = :can,
                      `mollie_connect_status_checked_at`    = :ts
                 WHERE `id` = :id"
            );
            $stmt->execute([
                ':status' => $derivedStatus,
                ':can'    => $canReceive ? 1 : 0,
                ':ts'     => $nowUtc,
                ':id'     => $tenantId,
            ]);

            $result['cached'] = [
                'onboarding_status'    => $derivedStatus,
                'can_receive_payments' => $canReceive,
                'checked_at'           => $nowUtc,
            ];
        } catch (\Throwable $cacheErr) {
            error_log("Mollie status cache write failed for tenant {$tenantId}: " . $cacheErr->getMessage());
        }
    } catch (\Throwable $e) {
        $result['error'] = 'Tenant check: ' . $e->getMessage();
    }
}

// ── 2) PLATFORM (superadmin) onboarding status ─────────────────────────
// The platform account receives the applicationFee; it must also be ready.
try {
    $ps = new PlatformSetting($db);
    $platformApiKey = $ps->get('mollie_connect_api_key') ?? '';
    if (!empty($platformApiKey)) {
        $platformMollie = new MollieService($platformApiKey, 'live');
        $result['platform_onboarding'] = $platformMollie->getOnboardingStatus();
    } else {
        $result['platform_onboarding'] = ['error' => 'Platform API key niet geconfigureerd'];
    }
} catch (\Throwable $e) {
    $result['platform_onboarding'] = ['error' => $e->getMessage()];
}

Response::success($result);
