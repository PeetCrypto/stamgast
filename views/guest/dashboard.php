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

// Get wallet balance (tenant-scoped for isolation safety)
$walletModel = new Wallet($db);
$wallet = $walletModel->findByUserAndTenant($userId, $tenantId);
$balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;
$pointsCents = $wallet ? (int) $wallet['points_cents'] : 0;

// Get account status for gated onboarding
$userModel = new User($db);
$accountStatus = $userModel->getAccountStatus($userId);
$user = $userModel->findById($userId);
$hasFcmToken = !empty($user['fcm_token']);
$emailVerified = !empty($user['email_verified_at']);
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);
$verificationRequired = (bool) ($tenant['verification_required'] ?? true);
$pointsEnabled = (bool) ($tenant['points_enabled'] ?? true);
$isUnverified = ($accountStatus !== 'active' && $verificationRequired);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
/* ── Gold Wallet Card (texteffects.dev style) ── */
.gold-wallet-card {
    position: relative;
    border: none;
    border-radius: 20px;
    background-clip: padding-box;
}

.gold-wallet-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 20px;
    padding: 2px;
    background: linear-gradient(135deg, #cfc09f, #634f2c, #cfc09f, #ffecb3, #cfc09f);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

/* ── Silver Action Card ── */
.silver-action-card {
    position: relative;
    border: none;
    border-radius: 16px;
    background-clip: padding-box;
    overflow: visible;
}

.silver-action-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 20px;
    padding: 2px;
    background: linear-gradient(135deg, #e8e8e8, #a0a0a0, #d0d0d0, #888888, #c0c0c0);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.gold-text {
    background: linear-gradient(to bottom, #cfc09f 27%, #ffecb3 40%, #3a2c0f 78%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    color: #fff;
    position: relative;
    font-size: 48px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.gold-text::after {
    background: none;
    content: attr(data-heading);
    inset: 0;
    z-index: -1;
    position: absolute;
    -webkit-text-fill-color: transparent;
    color: transparent;
    text-shadow:
        -1px 0 1px #c6bb9f,
        0 1px 1px #c6bb9f,
        5px 5px 10px rgba(0, 0, 0, 0.4),
        -5px -5px 10px rgba(0, 0, 0, 0.4);
}
</style>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Hoi, <?= sanitize($firstName) ?>!</h1>

    <!-- Wallet Card -->
    <div class="glass-card gold-wallet-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); text-align: center; position: relative;">
        <p class="text-secondary text-sm">Je saldo</p>

        <div id="balance-display" style="transition: filter 0.3s ease;">
            <h2 class="gold-text" data-heading="€ <?= number_format($balanceCents / 100, 2, ',', '.') ?>">€ <?= number_format($balanceCents / 100, 2, ',', '.') ?></h2>
        </div>

        <?php if ($pointsEnabled): ?>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);"><?= number_format($pointsCents / 100, 0) ?> punten</p>
        <?php endif; ?>
        <?php if (!$isUnverified): ?>
        <a href="<?= BASE_URL ?>/pay" class="btn btn-primary" style="margin-top: var(--space-md); display: inline-flex; align-items: center; gap: 8px;">Scan &amp; betaal <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg></a>
        <?php endif; ?>

    </div>

    <?php if (!$emailVerified && array_key_exists('email_verified_at', $user)): ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- STEP 1: Email verificatie (MOET eerst)                   -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(33,150,243,0.5); background: rgba(33,150,243,0.06); text-align: center;">
        <p style="font-size: 36px; margin-bottom: var(--space-sm);">📧</p>
        <p style="font-size: 18px; font-weight: 700; margin-bottom: 0.5rem; color: #2196F3;">E-mail nog niet geverifieerd</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
            We hebben een verificatiecode gestuurd naar <strong><?= sanitize($user['email']) ?></strong>.<br>
            Voer deze code in om je account te activeren.
        </p>
        <a href="<?= BASE_URL ?>/j/<?= sanitize($tenantSlug ?? ($_SESSION['tenant']['slug'] ?? '')) ?>/verify" class="btn btn-primary" style="background: #2196F3; border-color: #2196F3;">Verifieer je e-mail</a>
        <p style="color: var(--text-muted); font-size: 12px; margin-top: 0.75rem;">
            Geen code ontvangen? Controleer je spamfolder of vraag een nieuwe code aan.
        </p>
    </div>

    <?php elseif ($isUnverified): ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- STEP 2: Barman verificatie (alleen na email verificatie)   -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); border: 2px solid rgba(255,193,7,0.4); background: rgba(255,193,7,0.06); text-align: center;">
        <p style="font-size: 18px; color: #FFC107; font-weight: 600; margin-bottom: 0.5rem;">⚠️ Account niet geactiveerd</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">Hoi! Om je wallet te activeren en saldo te storten, moet je eenmalig je ID laten zien bij de bar. Zo houden we het veilig en legaal.</p>
        <a href="<?= BASE_URL ?>/pay" class="btn btn-outline" style="border-color: #FFC107; color: #FFC107;">Activeer bij de bar</a>
    </div>
    <?php endif; ?>

    <?php if (!$hasFcmToken && $emailVerified): ?>
    <!-- Push Notificaties VERPLICHT — blokkeert dashboard tot ingeschakeld (only after email verified) -->
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
                Push notificaties zijn momenteel niet beschikbaar. Je kunt de app gewoon gebruiken — probeer het later opnieuw via je profiel.
            </p>
            <button id="push-skip-btn" style="width:100%;margin-top:var(--space-md);background:none;border:none;color:var(--text-secondary);font-size:13px;cursor:pointer;text-decoration:underline;">Later instellen</button>
        </div>
    </div>
    <?php endif; ?>

     <!-- Quick Actions -->
     <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
         <a href="<?= BASE_URL ?>/wallet" class="glass-card silver-action-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
             <img src="<?= BASE_URL ?>/public/icons/wallet.svg" alt="Wallet" style="width: 86px; height: auto; margin: 0 auto var(--space-xs); display: block;">
             <p class="text-sm">Wallet</p>
         </a>
          <a href="<?= BASE_URL ?>/benefits" class="glass-card silver-action-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="48" height="48" style="margin: 0 auto var(--space-xs); display: block;"><defs><linearGradient id="silverGradient" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#A6AFB8"/><stop offset="25%" stop-color="#ffffff"/><stop offset="45%" stop-color="#E1E5E9"/><stop offset="55%" stop-color="#B5BDC4"/><stop offset="80%" stop-color="#ffffff"/><stop offset="100%" stop-color="#8E969E"/></linearGradient><filter id="softShadow" x="-10%" y="-10%" width="120%" height="120%"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#000000" flood-opacity="0.4"/></filter></defs><g filter="url(#softShadow)"><path d="M60,34 C48,16 34,22 41,34 C44,39 52,38 60,37" fill="none" stroke="url(#silverGradient)" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M60,34 C72,16 86,22 79,34 C76,39 68,38 60,37" fill="none" stroke="url(#silverGradient)" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="60" cy="35.5" r="2.5" fill="url(#silverGradient)"/><rect x="25" y="38" width="70" height="15" rx="4" fill="none" stroke="url(#silverGradient)" stroke-width="4.5" stroke-linejoin="round"/><path d="M30,53 L30,88 C30,93 34,97 39,97 L81,97 C86,97 90,93 90,88 L90,53" fill="none" stroke="url(#silverGradient)" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/><rect x="51" y="38" width="18" height="15" fill="url(#silverGradient)"/><rect x="51" y="53.5" width="18" height="41.5" fill="url(#silverGradient)"/></g></svg>
              <p class="text-sm">Mijn voordelen</p>
          </a>
         <a href="<?= BASE_URL ?>/inbox" class="glass-card silver-action-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit; position: relative;">
             <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128233;</p>
             <p class="text-sm">Inbox</p>
             <span class="notif-badge" id="inbox-badge" style="display:none;position:absolute;top:8px;right:8px;background:#4CAF50;color:#fff;font-size:11px;font-weight:700;min-width:20px;height:20px;border-radius:10px;align-items:center;justify-content:center;padding:0 5px;">0</span>
         </a>
         <a href="<?= BASE_URL ?>/profile" class="glass-card silver-action-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
             <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128100;</p>
             <p class="text-sm">Profiel</p>
         </a>
     </div>
</div>

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
    var overlay = document.getElementById('push-mandatory-overlay');
    if (!overlay) return; // PHP heeft overlay niet gerenderd (push al actief)

    // Al overgeslagen in deze sessie? Verberg direct
    try {
        if (sessionStorage.getItem('push_overlay_skipped') === '1') {
            overlay.style.display = 'none';
            return;
        }
    } catch(_) {}

    var btn = document.getElementById('push-enable-btn');
    var skipBtn = document.getElementById('push-skip-btn');
    var deniedMsg = document.getElementById('push-denied-msg');
    var unsupportedMsg = document.getElementById('push-unsupported-msg');

    // ── Skip / Later handler — altijd beschikbaar ──
    function dismissOverlay() {
        overlay.style.display = 'none';
        // Onthoud voor deze sessie dat de gebruiker heeft overgeslagen
        try { sessionStorage.setItem('push_overlay_skipped', '1'); } catch(_) {}
    }

    if (skipBtn) skipBtn.addEventListener('click', dismissOverlay);

    // ── Retry-logica: iOS PWA kan Notification API met vertraging beschikbaar maken ──
    var MAX_RETRIES = 5;
    var RETRY_DELAY = 1000; // ms
    var retryCount = 0;

    function tryInitPush() {
        // Al geaccepteerd? Overlay verbergen (token wordt door push.js afgehandeld)
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            overlay.style.display = 'none';
            return;
        }

        // Notification API beschikbaar? Toon juiste UI
        if (typeof Notification !== 'undefined') {
            setupPushUI();
            return;
        }

        // Notification API nog niet beschikbaar — retry op iOS PWA
        retryCount++;
        if (retryCount < MAX_RETRIES) {
            console.log('[Push Overlay] Notification API nog niet beschikbaar, retry ' + retryCount + '/' + MAX_RETRIES);
            setTimeout(tryInitPush, RETRY_DELAY);
        } else {
            // Na alle retries: toon unsupported maar laat skip-knop zichtbaar
            console.warn('[Push Overlay] Notification API niet beschikbaar na ' + MAX_RETRIES + ' retries');
            if (unsupportedMsg) unsupportedMsg.style.display = 'block';
            if (btn) btn.style.display = 'none';
        }
    }

    function setupPushUI() {
        // Geblokkeerd door gebruiker? Toon instructie
        if (Notification.permission === 'denied') {
            if (deniedMsg) deniedMsg.style.display = 'block';
            if (btn) {
                btn.textContent = 'Notificaties inschakelen';
                btn.disabled = false;
            }
        }

        // Knop handler
        if (btn) btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = 'Bezig...';

            if (window.FCMHandler && window.FCMHandler.subscribe) {
                window.FCMHandler.subscribe().then(function(result) {
                    if (result.granted) {
                        overlay.style.display = 'none';
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
    }

    // Start na korte delay zodat push.js en SW eerst kunnen initialiseren
    setTimeout(tryInitPush, 1000);
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
