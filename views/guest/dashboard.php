<?php
declare(strict_types=1);
/**
 * Guest Dashboard
 * REGULR.vip Loyalty Platform
 */

require_once __DIR__ . '/../../models/Wallet.php';

$db = Database::getInstance()->getConnection();
$userId = currentUserId();
$tenantId = currentTenantId();
$firstName = $_SESSION['first_name'] ?? 'Gast';

// Get wallet balance
$walletModel = new Wallet($db);
$wallet = $walletModel->findByUserId($userId);
$balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;
$pointsCents = $wallet ? (int) $wallet['points_cents'] : 0;

// Get account status for gated onboarding
$userModel = new User($db);
$accountStatus = $userModel->getAccountStatus($userId);
$user = $userModel->findById($userId);
$hasFcmToken = !empty($user['fcm_token']);
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);
$verificationRequired = (bool) ($tenant['verification_required'] ?? true);
$pointsEnabled = (bool) ($tenant['points_enabled'] ?? true);
$isUnverified = ($accountStatus !== 'active' && $verificationRequired);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Hoi, <?= sanitize($firstName) ?>!</h1>

    <!-- Wallet Card -->
    <div class="glass-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); border: 2px solid var(--accent-primary); text-align: center; position: relative; overflow: hidden;">
        <p class="text-secondary text-sm">Je saldo</p>

        <div id="balance-display">
            <p style="font-size: 48px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($balanceCents / 100, 2, ',', '.') ?></p>
        </div>

        <?php if ($pointsEnabled): ?>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);"><?= number_format($pointsCents / 100, 0) ?> punten</p>
        <?php endif; ?>
        <?php if (!$isUnverified): ?>
        <a href="<?= BASE_URL ?>/qr" class="btn btn-primary" style="margin-top: var(--space-md);">Betalen</a>
        <?php endif; ?>
    </div>

        <!-- Lock overlay (alleen als PIN ingesteld is — JS toont/verbergt dit) -->
        <div id="balance-lock" style="position:absolute;inset:0;display:none;align-items:center;justify-content:center;cursor:pointer;background:rgba(15,15,15,0.6);border-radius:inherit;z-index:2;">
            <div style="text-align:center;">
                <p style="font-size:32px;">&#128274;</p>
                <p class="text-sm text-secondary">Tik om saldo te bekijken</p>
            </div>
        </div>
    </div>

    <?php if ($isUnverified): ?>
    <!-- Unverified Account Banner -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(255,193,7,0.4); background: rgba(255,193,7,0.06); text-align: center;">
        <p style="font-size: 18px; color: #FFC107; font-weight: 600; margin-bottom: 0.5rem;">⚠️ Account niet geactiveerd</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">Hoi! Om je wallet te activeren en saldo te storten, moet je eenmalig je ID laten zien bij de bar. Zo houden we het veilig en legaal.</p>
        <a href="<?= BASE_URL ?>/qr" class="btn btn-outline" style="border-color: #FFC107; color: #FFC107;">Laat je QR zien aan de bar</a>
    </div>
    <?php endif; ?>

    <!-- PWA Install Banner — ALTIJD zichtbaar als niet in standalone/PWA mode -->
    <div id="pwa-install-banner" class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(33,150,243,0.4); background: rgba(33,150,243,0.06); text-align: center; display: none;">
        <!-- Android/Chrome: automatische install -->
        <div id="pwa-auto-install" style="display:none;">
            <p style="font-size: 36px; margin-bottom: var(--space-sm);">📲</p>
            <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">Installeer de app</p>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
                Voeg <strong><?= sanitize($tenant['name'] ?? APP_NAME) ?></strong> toe aan je thuisscherm voor de beste ervaring met FaceID en push berichten.
            </p>
            <button id="pwa-install-btn" class="btn btn-primary" style="margin-right: 8px;">Installeren</button>
            <button id="pwa-dismiss-btn" class="btn btn-secondary btn-sm">Later</button>
        </div>

        <!-- iOS Safari: handmatige instructie -->
        <div id="pwa-ios-install" style="display:none;">
            <p style="font-size: 36px; margin-bottom: var(--space-sm);">📲</p>
            <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">Zet op je thuisscherm</p>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
                Voor FaceID en de beste ervaring, voeg <strong><?= sanitize($tenant['name'] ?? APP_NAME) ?></strong> toe als app:
            </p>
            <div style="text-align:left;background:rgba(255,255,255,0.05);border-radius:12px;padding:var(--space-md);margin-bottom:var(--space-md);font-size:14px;line-height:2;">
                <p>1. Tik op het <strong>deel-icoon</strong>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                    onderaan je scherm
                </p>
                <p>2. Scroll naar <strong>"Zet op beginscherm"</strong></p>
                <p>3. Tik op <strong>"Toevoegen"</strong></p>
            </div>
            <button id="pwa-ios-dismiss" class="btn btn-secondary btn-sm">Begrepen</button>
        </div>

        <!-- Desktop browser: hint dat PWA beschikbaar is -->
        <div id="pwa-desktop-install" style="display:none;">
            <p style="font-size: 36px; margin-bottom: var(--space-sm);">📲</p>
            <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">Installeer als app</p>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
                Open deze pagina op je <strong>telefoon</strong> en voeg hem toe aan je thuisscherm voor FaceID en push berichten.
            </p>
            <button id="pwa-desktop-dismiss" class="btn btn-secondary btn-sm">Begrepen</button>
        </div>
    </div>

    <?php if (!$hasFcmToken): ?>
    <!-- Push Notificaties VERPLICHT — blokkeert dashboard tot ingeschakeld -->
    <div id="push-mandatory-overlay" style="position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;padding:var(--space-lg);">
        <div style="background:var(--glass-bg);border:2px solid rgba(76,175,80,0.5);border-radius:16px;padding:var(--space-xl);max-width:400px;width:100%;text-align:center;">
            <p style="font-size:36px;margin-bottom:var(--space-md);">🔔</p>
            <h2 style="font-size:20px;font-weight:700;margin-bottom:var(--space-sm);">Notificaties zijn verplicht</h2>
            <p style="color:var(--text-secondary);font-size:14px;margin-bottom:var(--space-lg);">
                Om berichten te ontvangen van <?= sanitize($tenant['name'] ?? APP_NAME) ?> moeten notificaties aanstaan. 
                Denk aan je saldo, betalingen en speciale aanbiedingen.
            </p>
            <button id="push-enable-btn" class="btn btn-primary" style="width:100%;margin-bottom:var(--space-sm);">Notificaties aanzetten</button>
            <p id="push-denied-msg" style="display:none;color:#F44336;font-size:13px;margin-top:var(--space-sm);">
                Notificaties zijn geblokkeerd in je apparaatinstellingen. Open je browser- of apparaatinstellingen om notificaties toe te staan voor deze app.
            </p>
            <p id="push-unsupported-msg" style="display:none;color:#F44336;font-size:13px;margin-top:var(--space-sm);">
                Je browser ondersteunt geen push notificaties. Gebruik Chrome, Safari of een andere moderne browser.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <a href="<?= BASE_URL ?>/qr" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#9203;</p>
            <p class="text-sm">QR Code</p>
        </a>
        <a href="<?= BASE_URL ?>/wallet" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128176;</p>
            <p class="text-sm">Wallet</p>
        </a>
        <a href="<?= BASE_URL ?>/inbox" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit; position: relative;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128233;</p>
            <p class="text-sm">Inbox</p>
            <span class="notif-badge" id="inbox-badge" style="display:none;position:absolute;top:8px;right:8px;background:#4CAF50;color:#fff;font-size:11px;font-weight:700;min-width:20px;height:20px;border-radius:10px;align-items:center;justify-content:center;padding:0 5px;">0</span>
        </a>
        <a href="<?= BASE_URL ?>/profile" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128100;</p>
            <p class="text-sm">Profiel</p>
        </a>
    </div>
</div>

<!-- PWA Install Prompt Logic — detecteert standalone mode -->
<script>
(function() {
    // ── Detect standalone / PWA mode ──
    var isStandalone = false;
    try {
        isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true
            || document.referrer.indexOf('android-app://') === 0;
    } catch(_) {}

    // Als al in PWA mode → geen banner tonen
    if (isStandalone) return;

    // Dismissed onthouden voor 7 dagen
    var DISMISS_KEY = 'pwa_install_dismissed';
    var DISMISS_DAYS = 7;
    try {
        var dismissed = localStorage.getItem(DISMISS_KEY);
        if (dismissed) {
            var elapsed = (Date.now() - parseInt(dismissed, 10)) / (1000 * 60 * 60 * 24);
            if (elapsed < DISMISS_DAYS) return;
            localStorage.removeItem(DISMISS_KEY);
        }
    } catch(_) {}

    function dismissBanner() {
        var banner = document.getElementById('pwa-install-banner');
        if (banner) banner.style.display = 'none';
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch(_) {}
    }

    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    var isMobile = /Mobi|Android/i.test(navigator.userAgent);

    var banner = document.getElementById('pwa-install-banner');
    if (!banner) return;

    // ── iOS Safari: handmatige instructie ──
    if (isIOS && isSafari) {
        var iosEl = document.getElementById('pwa-ios-install');
        if (iosEl) iosEl.style.display = 'block';
        banner.style.display = 'block';

        var iosDismiss = document.getElementById('pwa-ios-dismiss');
        if (iosDismiss) iosDismiss.addEventListener('click', dismissBanner);
        return;
    }

    // ── Android/Chrome: beforeinstallprompt ──
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        var autoEl = document.getElementById('pwa-auto-install');
        if (autoEl) autoEl.style.display = 'block';
        banner.style.display = 'block';
    });

    var installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) installBtn.addEventListener('click', function() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function() {
            deferredPrompt = null;
            dismissBanner();
        });
    });

    var dismissBtn = document.getElementById('pwa-dismiss-btn');
    if (dismissBtn) dismissBtn.addEventListener('click', dismissBanner);

    // ── Als geen beforeinstallprompt na 3s → desktop of niet-ondersteunde browser ──
    setTimeout(function() {
        if (deferredPrompt) return; // Android/Chrome heeft het al afgehandeld
        if (isIOS) return; // iOS heeft het al afgehandeld

        // Mobile maar geen beforeinstallprompt → toon iOS-achtige hint
        if (isMobile) {
            var iosEl = document.getElementById('pwa-ios-install');
            if (iosEl) iosEl.style.display = 'block';
            banner.style.display = 'block';
            var iosDismiss = document.getElementById('pwa-ios-dismiss');
            if (iosDismiss) iosDismiss.addEventListener('click', dismissBanner);
            return;
        }

        // Desktop browser → toon desktop hint
        var desktopEl = document.getElementById('pwa-desktop-install');
        if (desktopEl) desktopEl.style.display = 'block';
        banner.style.display = 'block';
        var desktopDismiss = document.getElementById('pwa-desktop-dismiss');
        if (desktopDismiss) desktopDismiss.addEventListener('click', dismissBanner);
    }, 3000);
})();
</script>

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script src="<?= BASE_URL ?>/public/js/push.js"></script>
<script>
// Fetch unread inbox count and show badge on Inbox card
fetch((window.__BASE_URL || '') + '/api/notification/check', {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }
})
.then(function(r) { return r.json(); })
.then(function(result) {
    if (result.success && result.data && result.data.unread_count > 0) {
        var badge = document.getElementById('inbox-badge');
        if (badge) {
            badge.textContent = result.data.unread_count > 99 ? '99+' : result.data.unread_count;
            badge.style.display = 'flex';
        }
    }
}).catch(function() {});
</script>

<!-- Push Mandatory Overlay — NA push.js zodat iOS Notification API beschikbaar is -->
<script>
(function() {
    // Wacht even zodat iOS de Notification API beschikbaar maakt in PWA mode
    setTimeout(function() {
        var overlay = document.getElementById('push-mandatory-overlay');
        if (!overlay) return; // PHP heeft overlay niet gerenderd (push al actief)

        var btn = document.getElementById('push-enable-btn');
        var deniedMsg = document.getElementById('push-denied-msg');
        var unsupportedMsg = document.getElementById('push-unsupported-msg');

        // Geen Notification support? Toon unsupported bericht
        if (typeof Notification === 'undefined') {
            if (unsupportedMsg) unsupportedMsg.style.display = 'block';
            if (btn) btn.disabled = true;
            return;
        }

        // Al geaccepteerd? Overlay verbergen (token wordt door push.js afgehandeld)
        if (Notification.permission === 'granted') {
            overlay.style.display = 'none';
            return;
        }

        // Geblokkeerd door gebruiker? Toon instructie
        if (Notification.permission === 'denied') {
            if (deniedMsg) deniedMsg.style.display = 'block';
            if (btn) {
                btn.textContent = 'Notificaties inschakelen';
                btn.disabled = false; // Keep clickable for retry attempt
            }
        }

        // Knop handler
        if (btn) btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = 'Bezig...';

            if (window.FCMHandler && window.FCMHandler.subscribe) {
                window.FCMHandler.subscribe().then(function(result) {
                    if (result.granted) {
                        // Success — verberg overlay
                        overlay.style.display = 'none';
                        // Clear oude flags
                        try { localStorage.removeItem('push_banner_dismissed'); } catch(_) {}
                        try { localStorage.removeItem('push_disabled'); } catch(_) {}
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Notificaties aanzetten';
                        if (result.reason === 'denied') {
                            if (deniedMsg) deniedMsg.style.display = 'block';
                        }
                    }
                });
            } else {
                // Fallback: direct permission request
                Notification.requestPermission().then(function(permission) {
                    if (permission === 'granted') {
                        overlay.style.display = 'none';
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Notificaties aanzetten';
                        if (permission === 'denied' && deniedMsg) {
                            deniedMsg.style.display = 'block';
                        }
                    }
                });
            }
        });
    }, 1000);
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
