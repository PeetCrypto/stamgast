<?php
declare(strict_types=1);
/**
 * REGULR.vip - Mollie Payment Return Page
 *
 * PUBLIC view (no auth required).
 *
 * WHY THIS PAGE EXISTS
 * --------------------
 * On iOS, a PWA running in "standalone" mode opens EXTERNAL navigations
 * (the Mollie checkout URL) in Safari. After the guest completes payment,
 * Mollie redirects back to app.regulr.vip — but that redirect lands in
 * Safari, NOT in the PWA. iOS PWA and Safari do NOT share cookies, so the
 * guest is effectively logged-out in Safari.
 *
 * This page handles BOTH contexts gracefully:
 *  1. Same browser context (guest is logged in) → redirect straight to /wallet
 *  2. Safari context (guest came from PWA, not logged in here) → show a
 *     friendly interstitial telling them to return to the installed app.
 *
 * The actual payment processing happens server-side via the Mollie webhook.
 * The PWA polls the wallet balance when it resumes (visibilitychange), so the
 * guest sees the updated balance the moment they switch back to the app.
 */

$bodyClass = 'payment-return-page';

// Resolve the tenant from ?slug= so we can show the correct tenant name and
// branding on this PUBLIC page (no session in the iOS Safari context).
$tenantSlug = trim($_GET['slug'] ?? '');
$tenantName = null; // set below if resolved; header falls back to APP_NAME

if ($tenantSlug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,98}[a-z0-9]$/', $tenantSlug)) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'SELECT `name`, `brand_color`, `secondary_color`, `logo_path`
             FROM `tenants` WHERE `slug` = :slug AND `is_active` = 1 LIMIT 1'
        );
        $stmt->execute([':slug' => $tenantSlug]);
        $returnTenant = $stmt->fetch();
        if ($returnTenant) {
            $tenantName = $returnTenant['name'];
            $brandColor = $returnTenant['brand_color'] ?? '#FFC107';
            $secondaryColor = $returnTenant['secondary_color'] ?? '#FF9800';
            if (!empty($returnTenant['logo_path'])) {
                $tenantLogo = $returnTenant['logo_path'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[payment_return] tenant resolve failed for slug="' . $tenantSlug . '": ' . $e->getMessage());
    }
}
// Used in the interstitial HTML below (falls back to APP_NAME if unresolved).
$returnTenantName = $tenantName ?? APP_NAME;

// Suppress the "Add to home screen" PWA install banner on THIS page only.
// This is a transient payment-confirmation screen — nagging the guest to
// install the app here is noisy and irrelevant (they just paid).
$suppressPwaBanner = true;

// Minimal header WITHOUT the auth-gated nav (this is a public page).
// We still load the shared header for PWA manifest + design system.
// Auth is NOT enforced here (added to $publicViews in index.php).
require __DIR__ . '/header.php';
?>

<main class="container payment-return-page__main">
    <div id="payment-return-card" class="info-card glass-card" style="text-align:center; padding: var(--space-xl); max-width: 420px; margin: 0 auto;">

        <!-- Success icon -->
        <div class="payment-return__icon">
            <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
        </div>

        <h1 id="payment-return__title" style="font-size: 24px; font-weight: 700; margin: var(--space-md) 0 var(--space-xs);">
            Betaling voltooid!
        </h1>

        <p id="payment-return__subtitle" style="color: var(--text-secondary); font-size: 15px; line-height: 1.5; margin-bottom: var(--space-lg);">
            Je saldo wordt automatisch bijgewerkt.
        </p>

        <!-- Loading spinner (shown until we know the context) -->
        <div id="payment-return__loading" style="display:flex; flex-direction:column; align-items:center; gap: var(--space-md); margin: var(--space-lg) 0;">
            <div class="payment-return__spinner"></div>
            <p style="font-size: 13px; color: var(--text-muted);">Een moment&hellip;</p>
        </div>

        <!-- Inline browser context: redirect to wallet (hidden until detected) -->
        <div id="payment-return__inline" style="display:none;">
            <button type="button" class="btn btn-primary" style="width:100%; padding: 14px; font-size: 16px;" onclick="window.location.href = (window.__BASE_URL||'') + '/wallet';">
                Naar mijn wallet
            </button>
        </div>

        <!-- PWA→Safari context: instruct to return to the app (hidden until detected) -->
        <div id="payment-return__pwa" style="display:none;">
            <div class="payment-return__steps">
                <div class="payment-return__step">
                    <span class="payment-return__step-num">1</span>
                    <span>Sluit dit scherm</span>
                </div>
                <div class="payment-return__step">
                    <span class="payment-return__step-num">2</span>
                    <span>Open de <strong id="payment-return__appname"><?= sanitize($returnTenantName) ?></strong> app</span>
                </div>
                <div class="payment-return__step">
                    <span class="payment-return__step-num">3</span>
                    <span>Je nieuwe saldo staat klaar &check;</span>
                </div>
            </div>
        </div>

    </div>
</main>

<style>
.payment-return-page__main {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-top: env(safe-area-inset-top);
}

.payment-return__icon {
    color: #2ecc71;
    width: 96px;
    height: 96px;
    margin: 0 auto;
    border-radius: 50%;
    background: rgba(46, 204, 113, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
}

.payment-return__spinner {
    width: 28px;
    height: 28px;
    border: 3px solid rgba(255,255,255,0.15);
    border-top-color: var(--accent-primary, #FFC107);
    border-radius: 50%;
    animation: pr-spin 0.8s linear infinite;
}
@keyframes pr-spin { to { transform: rotate(360deg); } }

.payment-return__steps {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    text-align: left;
    margin: var(--space-md) 0;
}
.payment-return__step {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: rgba(255,255,255,0.04);
    border-radius: 12px;
    font-size: 15px;
}
.payment-return__step-num {
    flex: 0 0 auto;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--accent-primary, #FFC107);
    color: #000;
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<!-- Alerts Container -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script>
(function() {
    'use strict';

    var loadingEl = document.getElementById('payment-return__loading');
    var inlineEl  = document.getElementById('payment-return__inline');
    var pwaEl     = document.getElementById('payment-return__pwa');

    /**
     * Detect the context we landed in.
     *
     * 1. Logged-in (same cookie jar as where the payment started, i.e. a
     *    normal browser tab) → go straight to /wallet.
     * 2. NOT logged-in → we are in Safari after a PWA-initiated payment.
     *    Show the "return to app" interstitial.
     */
    function detectContext() {
        var baseUrl = window.__BASE_URL || '';
        fetch(baseUrl + '/api/auth/session', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var payload = (res && res.success && res.data) ? res.data : {};
                // API returns { authenticated, user: { id, role, ... } }
                // when logged in, or { authenticated:false, user:null } when not.
                var user = payload.user || null;
                var isLoggedIn = !!(payload.authenticated && user && user.id && user.role === 'guest');

                loadingEl.style.display = 'none';

                if (isLoggedIn) {
                    // Same session → wallet is reachable. Auto-redirect.
                    inlineEl.style.display = 'block';
                    // Small delay so the success state registers visually.
                    setTimeout(function() {
                        window.location.href = baseUrl + '/wallet?from_payment=1';
                    }, 600);
                } else {
                    // PWA → Safari context: no shared session.
                    pwaEl.style.display = 'block';
                }
            })
            .catch(function() {
                // Network/API failure: fall back to the PWA interstitial
                // (safer than redirecting to a login page).
                loadingEl.style.display = 'none';
                pwaEl.style.display = 'block';
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', detectContext);
    } else {
        detectContext();
    }
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
