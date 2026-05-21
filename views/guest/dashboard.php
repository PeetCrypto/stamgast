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
    <div class="glass-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); border: 2px solid var(--accent-primary); text-align: center;">
        <p class="text-secondary text-sm">Je saldo</p>
        <p style="font-size: 48px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($balanceCents / 100, 2, ',', '.') ?></p>
        <?php if ($pointsEnabled): ?>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);"><?= number_format($pointsCents / 100, 0) ?> punten</p>
        <?php endif; ?>
        <?php if (!$isUnverified): ?>
        <a href="<?= BASE_URL ?>/wallet" class="btn btn-primary" style="margin-top: var(--space-md);">Opwaarderen</a>
        <?php endif; ?>
    </div>

    <?php if ($isUnverified): ?>
    <!-- Unverified Account Banner -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(255,193,7,0.4); background: rgba(255,193,7,0.06); text-align: center;">
        <p style="font-size: 18px; color: #FFC107; font-weight: 600; margin-bottom: 0.5rem;">⚠️ Account niet geactiveerd</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">Hoi! Om je wallet te activeren en saldo te storten, moet je eenmalig je ID laten zien bij de bar. Zo houden we het veilig en legaal.</p>
        <a href="<?= BASE_URL ?>/qr" class="btn btn-outline" style="border-color: #FFC107; color: #FFC107;">Laat je QR zien aan de bar</a>
    </div>
    <?php endif; ?>

    <!-- PWA Install Banner (Chrome/Android — eenmalig na QR-registratie) -->
    <div id="pwa-install-banner" class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(33,150,243,0.4); background: rgba(33,150,243,0.06); text-align: center; display: none;">
        <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">📲 Voeg toe aan je thuisscherm</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
            Voeg <strong><?= sanitize($tenant['name'] ?? APP_NAME) ?></strong> toe als app voor snelle toegang.
        </p>
        <div style="display: flex; gap: var(--space-sm); justify-content: center;">
            <button id="pwa-install-btn" class="btn btn-primary">Installeren</button>
            <button id="pwa-dismiss-btn" class="btn btn-secondary">Niet nu</button>
        </div>
    </div>

    <!-- iOS Safari fallback instructie -->
    <div id="pwa-ios-hint" class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(33,150,243,0.4); background: rgba(33,150,243,0.06); text-align: center; display: none;">
        <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">📲 Quick tip</p>
        <p style="color: var(--text-secondary); font-size: 14px;">
            Tik op het <strong>deel-icoon</strong>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            en kies <strong>"Zet op beginscherm"</strong> om <?= sanitize($tenant['name'] ?? APP_NAME) ?> als app te gebruiken.
        </p>
        <button id="pwa-ios-dismiss" class="btn btn-secondary btn-sm" style="margin-top: 0.5rem;">Begrepen</button>
    </div>

    <?php if (!$hasFcmToken): ?>
    <!-- Push Notificaties Activeren (iOS PWA + alle browsers) -->
    <div id="push-enable-banner" class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(76,175,80,0.4); background: rgba(76,175,80,0.06); text-align: center; display: none;">
        <p style="font-size: 18px; font-weight: 600; margin-bottom: 0.5rem;">🔔 Notificaties aanzetten</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
            Mis geen acties meer! Ontvang push berichten over je saldo, betalingen en aanbiedingen.
        </p>
        <button id="push-enable-btn" class="btn btn-primary" style="margin-right: 8px;">Notificaties aanzetten</button>
        <button id="push-enable-dismiss" class="btn btn-secondary btn-sm">Liever niet</button>
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

    <a href="<?= BASE_URL ?>/logout?return=<?= urlencode('/j/' . $tenant['slug']) ?>" class="btn btn-secondary">Uitloggen</a>
</div>

<!-- PWA Install Prompt Logic -->
<script>
(function() {
    var showPrompt = false;
    try { showPrompt = localStorage.getItem('show_pwa_prompt') === '1'; } catch(_) {}

    if (!showPrompt) return;

    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

    // iOS Safari: show static hint
    if (isIOS && isSafari) {
        var iosEl = document.getElementById('pwa-ios-hint');
        if (iosEl) iosEl.style.display = 'block';
        var iosDismiss = document.getElementById('pwa-ios-dismiss');
        if (iosDismiss) iosDismiss.addEventListener('click', function() {
            iosEl.style.display = 'none';
            try { localStorage.removeItem('show_pwa_prompt'); } catch(_) {}
        });
        return;
    }

    // Chrome/Android: use beforeinstallprompt
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        var banner = document.getElementById('pwa-install-banner');
        if (banner) banner.style.display = 'block';
    });

    var installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) installBtn.addEventListener('click', function() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function() {
            deferredPrompt = null;
            cleanup();
        });
    });

    var dismissBtn = document.getElementById('pwa-dismiss-btn');
    if (dismissBtn) dismissBtn.addEventListener('click', cleanup);

    function cleanup() {
        var banner = document.getElementById('pwa-install-banner');
        if (banner) banner.style.display = 'none';
        try { localStorage.removeItem('show_pwa_prompt'); } catch(_) {}
    }

    // Safety: clear flag after 5s if no beforeinstallprompt fires
    setTimeout(function() {
        if (!deferredPrompt) {
            try { localStorage.removeItem('show_pwa_prompt'); } catch(_) {}
        }
    }, 5000);
})();
</script>

<script src="<?= BASE_URL ?>/public/js/app.js"></script>
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

<!-- Push Enable Banner — NA push.js zodat iOS Notification API beschikbaar is -->
<script>
(function() {
    // Wacht even zodat iOS de Notification API beschikbaar maakt in PWA mode
    setTimeout(function() {
        var banner = document.getElementById('push-enable-banner');
        if (!banner) return;

        // Geen Notification support? Dan ook niet tonen
        if (typeof Notification === 'undefined') return;
        // Al geaccepteerd? Verbergen
        if (Notification.permission === 'granted') return;
        // Geblokkeerd? Verbergen
        if (Notification.permission === 'denied') return;

        // Permanent dismissed? (localStorage — persisteert over sessies heen)
        try { if (localStorage.getItem('push_banner_dismissed') === '1') return; } catch(_) {}

        banner.style.display = 'block';

        var btn = document.getElementById('push-enable-btn');
        var dismissBtn = document.getElementById('push-enable-dismiss');

        if (btn) btn.addEventListener('click', function() {
            // Use FCMHandler.subscribe() → permission + FCM token + DB
            if (window.FCMHandler && window.FCMHandler.subscribe) {
                window.FCMHandler.subscribe().then(function(result) {
                    if (result.granted) {
                        banner.style.display = 'none';
                    } else {
                        banner.style.display = 'none';
                        try { localStorage.setItem('push_banner_dismissed', '1'); } catch(_) {}
                        if (result.reason === 'denied') {
                            try { localStorage.setItem('push_disabled', '1'); } catch(_) {}
                        }
                    }
                });
            } else {
                // Fallback: direct permission request
                Notification.requestPermission().then(function(permission) {
                    banner.style.display = 'none';
                    if (permission !== 'granted') {
                        try { localStorage.setItem('push_banner_dismissed', '1'); } catch(_) {}
                    }
                });
            }
        });

        if (dismissBtn) dismissBtn.addEventListener('click', function() {
            banner.style.display = 'none';
            try { localStorage.setItem('push_banner_dismissed', '1'); } catch(_) {}
            // Also set push_disabled so push.js doesn't auto-subscribe later
            try { localStorage.setItem('push_disabled', '1'); } catch(_) {}
        });
    }, 1000);
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
