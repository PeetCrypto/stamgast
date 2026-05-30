<?php
/**
 * PWA Install Banner — for guest auth pages (join/login)
 * Shows a slim fixed banner on mobile prompting the user to install the PWA.
 *
 * Expected variables (optional — fall back to defaults):
 *   $tenantName  — venue name
 *   $tenantSlug  — venue slug (for dismiss key uniqueness)
 */
$bannerTenantName = $tenantName ?? $tenant['name'] ?? APP_NAME;
$bannerSlug       = $tenantSlug ?? $tenant['slug'] ?? '';
?>

<!-- PWA Install Banner — fixed top bar on mobile -->
<div id="pwa-auth-banner" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 9999;
    display: none;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
">
    <!-- Slim collapsed bar -->
    <div id="pwa-auth-bar" style="
        background: rgba(33,150,243,0.95);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        color: #fff;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        box-shadow: 0 2px 12px rgba(0,0,0,0.3);
    ">
        <span style="font-size: 22px; flex-shrink: 0;">📲</span>
        <span id="pwa-auth-bar-text" style="flex: 1; font-size: 14px; font-weight: 500; line-height: 1.3;">
            Voeg <strong><?= sanitize($bannerTenantName) ?></strong> toe aan je thuisscherm
        </span>
        <button id="pwa-auth-dismiss" onclick="event.stopPropagation();" style="
            background: none;
            border: none;
            color: rgba(255,255,255,0.8);
            font-size: 22px;
            cursor: pointer;
            padding: 0 0 0 8px;
            line-height: 1;
            flex-shrink: 0;
        ">✕</button>
    </div>

    <!-- Expanded iOS instructions panel -->
    <div id="pwa-auth-ios-panel" style="
        display: none;
        background: rgba(33,150,243,0.98);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        color: #fff;
        padding: 16px 20px 20px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
    ">
        <p style="font-size: 15px; font-weight: 600; margin: 0 0 10px 0;">
            Zo zet je de app op je iPhone:
        </p>
        <div style="font-size: 14px; line-height: 2.2;">
            <p style="margin: 0;">1. Tik op het <strong>deel-icoon</strong>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                onderaan je scherm
            </p>
            <p style="margin: 0;">2. Scroll naar <strong>"Zet op beginscherm"</strong></p>
            <p style="margin: 0;">3. Tik op <strong>"Toevoegen"</strong></p>
        </div>
        <button id="pwa-auth-ios-ok" style="
            margin-top: 12px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.4);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 8px 24px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
        ">Begrepen</button>
    </div>
</div>

<!-- Push page content down so banner doesn't overlap the form -->
<div id="pwa-auth-spacer" style="display: none; height: 0px;"></div>

<script>
(function() {
    // ── Skip if already in PWA / standalone mode ──
    var isStandalone = false;
    try {
        isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true
            || document.referrer.indexOf('android-app://') === 0;
    } catch(_) {}
    if (isStandalone) return;

    // ── Only show on mobile ──
    var isMobile = /Mobi|Android/i.test(navigator.userAgent);
    if (!isMobile) return;

    // ── No dismiss persistence — banner keeps showing until PWA is installed ──
    // The only way to permanently hide this banner is by installing the PWA (standalone mode check above)

    var banner   = document.getElementById('pwa-auth-banner');
    var spacer   = document.getElementById('pwa-auth-spacer');
    var bar      = document.getElementById('pwa-auth-bar');
    var iosPanel = document.getElementById('pwa-auth-ios-panel');
    var barText  = document.getElementById('pwa-auth-bar-text');
    var dismissBtn  = document.getElementById('pwa-auth-dismiss');
    var iosOkBtn    = document.getElementById('pwa-auth-ios-ok');
    if (!banner || !bar) return;

    var isIOS    = /iPad|iPhone|iPod/.test(navigator.userAgent);
    var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

    function hideBanner() {
        banner.style.display = 'none';
        if (spacer) spacer.style.display = 'none';
        // Not persisted — banner returns on next page load
    }

    // ── Android Chrome: beforeinstallprompt ──
    var deferredPrompt = null;

    if (!isIOS) {
        // Change bar text for Android
        barText.innerHTML = 'Installeer de app van <strong><?= sanitize($bannerTenantName) ?></strong>';

        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            showBanner();
        });

        // Fallback: if no beforeinstallprompt after 2s, still show the banner
        // This covers non-Chrome Android browsers where beforeinstallprompt never fires
        setTimeout(function() {
            if (deferredPrompt) return; // already handled by beforeinstallprompt
            showBanner();
        }, 2000);

        bar.addEventListener('click', function() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function() {
                    deferredPrompt = null;
                    hideBanner();
                });
            }
        });
    }

    // ── iOS Safari: expand instructions on tap ──
    if (isIOS && isSafari) {
        barText.innerHTML = 'Voeg <strong><?= sanitize($bannerTenantName) ?></strong> toe aan je thuisscherm';
        var iosExpanded = false;

        bar.addEventListener('click', function() {
            if (iosExpanded) {
                iosPanel.style.display = 'none';
                iosExpanded = false;
            } else {
                iosPanel.style.display = 'block';
                iosExpanded = true;
            }
        });

        if (iosOkBtn) {
            iosOkBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                hideBanner();
            });
        }

        showBanner();
    }

    // ── Non-Safari iOS (e.g. Chrome on iOS) — just show the slim bar ──
    if (isIOS && !isSafari) {
        barText.innerHTML = 'Open in <strong>Safari</strong> en voeg toe aan thuisscherm';
        bar.addEventListener('click', function() {
            // Just dismiss — can't install from Chrome on iOS
        });

        showBanner();
    }

    // ── Dismiss button ──
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            hideBanner();
        });
    }

    function showBanner() {
        if (banner.style.display === 'block') return;
        banner.style.display = 'block';
        if (spacer) {
            spacer.style.display = 'block';
            spacer.style.height = '50px';
        }
    }
})();
</script>
