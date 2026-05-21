/**
 * REGULR.vip - App Lock Module
 * Core lockscreen: auto-lock na achtergrond, FaceID/PIN unlock, sensitive action gates
 * Gast kiest zelf: PIN-only, FaceID-only, of beide
 * Alleen actief voor role === 'guest'
 * Registreert als window.REGULR.appLock
 */
(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    var LOCK_TIMEOUT = 60;          // seconden achtergrond voordat lock
    var PIN_MAX_ATTEMPTS = 5;       // foute PIN pogingen → cooldown
    var PIN_LOCKOUT_ATTEMPTS = 10;  // foute PIN pogingen → volledige logout
    var COOLDOWN_SECONDS = 60;      // cooldown periode na MAX_ATTEMPTS
    var BASE = window.__BASE_URL || '';

    console.log('[AppLock] Script loaded - checking localStorage:');
    try {
        console.log('[AppLock]   regulr_pin_hash:', localStorage.getItem('regulr_pin_hash') ? 'SET' : 'NOT SET');
        console.log('[AppLock]   regulr_webauthn_enabled:', localStorage.getItem('regulr_webauthn_enabled'));
    } catch(e) { console.log('[AppLock]   localStorage error:', e.message); }

    // ============================================
    // STATE
    // ============================================
    var locked = false;
    var backgroundTimestamp = null;
    var pendingAuthResolvers = [];
    var failedAttempts = 0;
    var cooldownActive = false;
    var lockscreenInjected = false;

    // ============================================
    // GUARD: alleen voor gasten met PIN en/of WebAuthn ingesteld
    // ============================================
    function hasPin() {
        try {
            var pinHash = localStorage.getItem('regulr_pin_hash');
            // Also check sessionStorage as backup
            if (!pinHash) {
                pinHash = sessionStorage.getItem('regulr_pin_hash');
            }
            return !!pinHash;
        } catch (_) {
            return false;
        }
    }

    function hasWebAuthn() {
        try {
            var enabled = localStorage.getItem('regulr_webauthn_enabled') === '1';
            // Also check sessionStorage as backup
            if (!enabled) {
                enabled = sessionStorage.getItem('regulr_webauthn_enabled') === '1';
            }
            return enabled;
        } catch (_) {
            return false;
        }
    }

    function isActive() {
        // Script is only loaded for guests (see header.php), 
        // so we only need to check if PIN or WebAuthn is enabled
        return hasPin() || hasWebAuthn();
    }

    // ============================================
    // PIN HASH (SHA-256, zelfde als pin-setup.js)
    // ============================================
    async function hashPin(pin) {
        if (window.crypto && crypto.subtle) {
            try {
                var encoder = new TextEncoder();
                var data = encoder.encode(pin + '__regulr_salt__');
                var hashBuffer = await crypto.subtle.digest('SHA-256', data);
                return Array.from(new Uint8Array(hashBuffer))
                    .map(function(b) { return b.toString(16).padStart(2, '0'); })
                    .join('');
            } catch (e) {
                // fallback
            }
        }
        return fallbackHash(pin + '__regulr_salt__');
    }

    function fallbackHash(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) {
            var chr = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + chr;
            hash |= 0;
        }
        return Math.abs(hash).toString(16).padStart(8, '0') +
               Math.abs(hash * 31).toString(16).padStart(8, '0');
    }

    // ============================================
    // BASE64URL HELPERS
    // ============================================
    function base64urlToArrayBuffer(base64url) {
        var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4 !== 0) base64 += '=';
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function arrayBufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    // ============================================
    // LOCKSCREEN DOM
    // ============================================
    function injectLockscreen() {
        if (lockscreenInjected) return;
        lockscreenInjected = true;

        var overlay = document.createElement('div');
        overlay.id = 'app-lock-screen';
        overlay.className = 'app-lock-screen';
        overlay.innerHTML =
            '<div class="lock-content">' +
                '<div class="lock-icon">&#128274;</div>' +
                '<h2 class="lock-title">REGULR.vip</h2>' +
                '<p class="lock-subtitle">Ontgrendel om verder te gaan</p>' +

                '<button id="lock-biometric-btn" class="lock-biometric-btn" style="display:none;">' +
                    '&#128100; FaceID / Vingerafdruk' +
                '</button>' +

                '<p class="lock-divider" id="lock-divider" style="display:none;">of voer je PIN in</p>' +

                '<div class="lock-pin-dots" id="lock-pin-dots" style="display:none;">' +
                    '<div class="lock-pin-dot"></div>' +
                    '<div class="lock-pin-dot"></div>' +
                    '<div class="lock-pin-dot"></div>' +
                    '<div class="lock-pin-dot"></div>' +
                '</div>' +

                '<div class="lock-keypad" id="lock-keypad" style="display:none;">' +
                    '<button class="lock-key" data-digit="1">1</button>' +
                    '<button class="lock-key" data-digit="2">2</button>' +
                    '<button class="lock-key" data-digit="3">3</button>' +
                    '<button class="lock-key" data-digit="4">4</button>' +
                    '<button class="lock-key" data-digit="5">5</button>' +
                    '<button class="lock-key" data-digit="6">6</button>' +
                    '<button class="lock-key" data-digit="7">7</button>' +
                    '<button class="lock-key" data-digit="8">8</button>' +
                    '<button class="lock-key" data-digit="9">9</button>' +
                    '<button class="lock-key lock-key--empty" data-digit=""></button>' +
                    '<button class="lock-key" data-digit="0">0</button>' +
                    '<button class="lock-key lock-key--back" data-digit="backspace">&#9003;</button>' +
                '</div>' +

                '<p class="lock-error" id="lock-error"></p>' +
                '<p class="lock-attempts" id="lock-attempts"></p>' +
            '</div>';

        document.body.appendChild(overlay);
        
        // Set z-index to be below notifications (which use z-index 10000)
        overlay.style.zIndex = '9999';

        // Keypad event listeners
        var keypad = document.getElementById('lock-keypad');
        if (keypad) {
            keypad.addEventListener('click', function(e) {
                var btn = e.target.closest('.lock-key');
                if (!btn) return;
                var digit = btn.dataset.digit;
                if (digit === '') return;
                handleKeypress(digit);
            });
        }

        // Biometric button
        var bioBtn = document.getElementById('lock-biometric-btn');
        if (bioBtn) {
            bioBtn.addEventListener('click', function() {
                tryBiometricUnlock();
            });
        }
    }

    /**
     * Configureer lockscreen UI op basis van ingeschakelde methoden:
     * - WebAuthn-only → toon alleen biometric knop, auto-trigger
     * - PIN-only → toon alleen keypad
     * - Beide → toon biometric knop + "of" + keypad
     */
    function configureLockscreen() {
        var pinActive = hasPin();
        var webauthnActive = hasWebAuthn();

        var bioBtn = document.getElementById('lock-biometric-btn');
        var divider = document.getElementById('lock-divider');
        var pinDots = document.getElementById('lock-pin-dots');
        var keypad = document.getElementById('lock-keypad');

        if (webauthnActive && bioBtn) {
            bioBtn.style.display = 'block';
        } else if (bioBtn) {
            bioBtn.style.display = 'none';
        }

        if (pinActive) {
            if (pinDots) pinDots.style.display = 'flex';
            if (keypad) keypad.style.display = 'grid';
        } else {
            if (pinDots) pinDots.style.display = 'none';
            if (keypad) keypad.style.display = 'none';
        }

        // Divider alleen tonen als beide methoden beschikbaar zijn
        if (webauthnActive && pinActive && divider) {
            divider.style.display = 'block';
        } else if (divider) {
            divider.style.display = 'none';
        }
    }

    // Current PIN entry state
    var currentPinEntry = '';

    function showLockscreen() {
        var el = document.getElementById('app-lock-screen');
        if (!el) {
            injectLockscreen();
            el = document.getElementById('app-lock-screen');
        }
        if (el) {
            el.classList.add('active');
            currentPinEntry = '';
            updatePinDots('');
            configureLockscreen();

            // Auto-trigger biometric als WebAuthn-only
            if (hasWebAuthn() && !hasPin()) {
                setTimeout(function() {
                    tryBiometricUnlock();
                }, 300);
            }
        }
    }

    function hideLockscreen() {
        var el = document.getElementById('app-lock-screen');
        if (el) {
            el.classList.remove('active');
        }
        currentPinEntry = '';
        updatePinDots('');
        clearError();
    }

    // ============================================
    // PIN INPUT HANDLING (keypad-based)
    // ============================================
    function updatePinDots(value) {
        var dots = document.querySelectorAll('#lock-pin-dots .lock-pin-dot');
        dots.forEach(function(dot, i) {
            dot.classList.remove('filled', 'error', 'success');
            if (i < value.length) dot.classList.add('filled');
        });
    }

    function handleKeypress(digit) {
        if (cooldownActive) return;

        if (digit === 'backspace') {
            currentPinEntry = currentPinEntry.slice(0, -1);
            updatePinDots(currentPinEntry);
            return;
        }

        if (currentPinEntry.length >= 4) return;

        currentPinEntry += digit;
        updatePinDots(currentPinEntry);

        if (currentPinEntry.length === 4) {
            handlePinSubmit(currentPinEntry);
        }
    }

    async function handlePinSubmit(pin) {
        if (cooldownActive) return;

        var valid = await verifyPin(pin);

        if (valid) {
            failedAttempts = 0;
            try { sessionStorage.setItem('regulr_pin_fails', '0'); } catch (_) {}
            var dots = document.querySelectorAll('#lock-pin-dots .lock-pin-dot');
            dots.forEach(function(d) { d.classList.remove('filled'); d.classList.add('success'); });
            setTimeout(function() {
                unlock('pin');
            }, 300);
        } else {
            failedAttempts++;
            try { sessionStorage.setItem('regulr_pin_fails', String(failedAttempts)); } catch (_) {}

            var dots = document.querySelectorAll('#lock-pin-dots .lock-pin-dot');
            dots.forEach(function(d) { d.classList.add('error'); });

            currentPinEntry = '';
            setTimeout(function() { updatePinDots(''); }, 400);

            if (failedAttempts >= PIN_LOCKOUT_ATTEMPTS) {
                showLockError('Te veel foute pogingen');
                setTimeout(function() {
                    window.location.href = BASE + '/logout';
                }, 1000);
                return;
            }

            if (failedAttempts >= PIN_MAX_ATTEMPTS) {
                showLockError('Te veel foute pogingen. Wacht even...');
                startCooldown();
                return;
            }

            showLockError('Onjuiste PIN');
            updateAttemptsDisplay();
        }
    }

    async function verifyPin(enteredPin) {
        var hash = await hashPin(enteredPin);
        var stored = null;
        try { stored = localStorage.getItem('regulr_pin_hash'); } catch (_) {}
        return hash === stored;
    }

    function showLockError(message) {
        var el = document.getElementById('lock-error');
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    function clearError() {
        var el = document.getElementById('lock-error');
        if (el) {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function updateAttemptsDisplay() {
        var el = document.getElementById('lock-attempts');
        if (el && failedAttempts > 0) {
            el.textContent = failedAttempts + ' van ' + PIN_LOCKOUT_ATTEMPTS + ' pogingen';
        }
    }

    function startCooldown() {
        cooldownActive = true;

        var remaining = COOLDOWN_SECONDS;
        var interval = setInterval(function() {
            remaining--;
            showLockError('Probeer opnieuw over ' + remaining + ' seconden');
            if (remaining <= 0) {
                clearInterval(interval);
                cooldownActive = false;
                failedAttempts = 0;
                try { sessionStorage.setItem('regulr_pin_fails', '0'); } catch (_) {}
                clearError();
                updateAttemptsDisplay();
            }
        }, 1000);
    }

    // ============================================
    // BIOMETRIC UNLOCK (WebAuthn)
    // ============================================
    async function tryBiometricUnlock() {
        if (!window.PublicKeyCredential) return;

        var bioBtn = document.getElementById('lock-biometric-btn');
        if (bioBtn) {
            bioBtn.disabled = true;
            bioBtn.textContent = '⏳ Bezig...';
        }

        try {
            // 1. Vraag authentication challenge aan van server
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = csrfMeta ? csrfMeta.content : '';

            console.log('[AppLock] Requesting authenticate-options...');
            var optionsResp = await fetch(BASE + '/api/auth/webauthn/authenticate-options', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            });

            var optionsData = await optionsResp.json();
            if (!optionsData.success) throw new Error('Challenge failed: ' + (optionsData.error || 'unknown'));

            console.log('[AppLock] authenticate-options success, starting WebAuthn get...');
            var serverOptions = optionsData.data;

            // Log rpId and credential count
            console.log('[AppLock] rpId:', serverOptions.rpId, 'credentials:', (serverOptions.allowCredentials || []).length);

            var publicKey = {
                challenge: base64urlToArrayBuffer(serverOptions.challenge),
                rpId: serverOptions.rpId,
                allowCredentials: (serverOptions.allowCredentials || []).map(function(c) {
                    return {
                        type: c.type,
                        id: base64urlToArrayBuffer(c.id),
                        transports: c.transports || ['internal']
                    };
                }),
                timeout: serverOptions.timeout || 60000,
                userVerification: serverOptions.userVerification || 'required'
            };

            var assertion = await navigator.credentials.get({ publicKey: publicKey });
            console.log('[AppLock] WebAuthn get success, sending to server...');

            var responseBody = {
                id: assertion.id,
                rawId: arrayBufferToBase64url(assertion.rawId),
                response: {
                    clientDataJSON: arrayBufferToBase64url(assertion.response.clientDataJSON),
                    authenticatorData: arrayBufferToBase64url(assertion.response.authenticatorData),
                    signature: arrayBufferToBase64url(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? arrayBufferToBase64url(assertion.response.userHandle) : null
                },
                type: assertion.type
            };

            // Log de credential ID die naar de server gaat
            console.log('[AppLock] Sending credential ID:', responseBody.id);
            console.log('[AppLock] Sending clientDataJSON origin:', JSON.parse(atob(responseBody.response.clientDataJSON.replace(/-/g,'+').replace(/_/g,'/').replace(/=/g,'').padEnd(responseBody.response.clientDataJSON.length + (4 - responseBody.response.clientDataJSON.length % 4) % 4, '='))).origin);

            var verifyResp = await fetch(BASE + '/api/auth/webauthn/authenticate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(responseBody)
            });

            console.log('[AppLock] authenticate response status:', verifyResp.status);
            var result = await verifyResp.json();
            console.log('[AppLock] authenticate result:', result);
            if (result.success) {
                failedAttempts = 0;
                try { sessionStorage.setItem('regulr_pin_fails', '0'); } catch (_) {}
                unlock('biometric');
            } else {
                console.warn('[AppLock] Server error:', result.error);
                var errMsg = result.error || 'Onbekende fout';
                // Toon foutmelding direct (ook als PIN beschikbaar is, for debugging)
                showLockError(errMsg);
                // Herstel de knop
                if (bioBtn) {
                    bioBtn.disabled = false;
                    bioBtn.innerHTML = '&#128100; FaceID / Vingerafdruk';
                }
                return; // Stop hier, gooi geen error meer
            }
        } catch (e) {
            console.warn('[AppLock] Biometric unlock failed/cancelled:', e.message);
            if (bioBtn) {
                bioBtn.disabled = false;
                bioBtn.innerHTML = '&#128100; FaceID / Vingerafdruk';
            }

            // Bepaal betere foutmelding op basis van error
            var errorMsg = e.message || '';
            if (errorMsg.indexOf('cancelled') !== -1 || errorMsg.indexOf('NotAllowedError') !== -1) {
                // User cancelled - toon geen error als PIN beschikbaar is
                if (!hasPin()) {
                    showLockError('Biometrie geannuleerd. Probeer opnieuw.');
                }
            } else if (errorMsg.indexOf('verification failed') !== -1) {
                // Server verification failed
                showLockError('Verificatie mislukt. Probeer opnieuw.');
            } else if (errorMsg.indexOf('No credentials') !== -1) {
                showLockError('Geen FaceID geregistreerd. Schakel in via profiel.');
            } else if (errorMsg.indexOf('network') !== -1 || errorMsg.indexOf('fetch') !== -1) {
                showLockError('Verbindingsfout. Controleer je internet.');
            } else {
                // Andere fout - toon alleen als geen PIN beschikbaar
                if (!hasPin()) {
                    showLockError('Biometrie fout: ' + (errorMsg || 'Probeer opnieuw.'));
                }
            }
        }
    }

    // ============================================
    // LOCK / UNLOCK / REQUIRE AUTH
    // ============================================
    function lock() {
        if (!isActive()) return;
        locked = true;
        showLockscreen();
    }

    function unlock(method) {
        locked = false;
        hideLockscreen();

        // Backup flags to sessionStorage for cache-clear protection
        try {
            if (hasPin()) sessionStorage.setItem('regulr_pin_hash', '1');
            if (hasWebAuthn()) sessionStorage.setItem('regulr_webauthn_enabled', '1');
            // Mark as unlocked for this session — voorkomt lock bij page-navigatie
            sessionStorage.setItem('regulr_app_unlocked', '1');
        } catch (_) {}

        var resolvers = pendingAuthResolvers.slice();
        pendingAuthResolvers = [];
        resolvers.forEach(function(resolve) {
            resolve(true);
        });
    }

    /**
     * Public API: requireAuth()
     * Retourneert Promise<boolean>
     */
    function requireAuth() {
        return new Promise(function(resolve) {
            if (!isActive()) {
                resolve(true);
                return;
            }
            if (!locked) {
                resolve(true);
                return;
            }
            pendingAuthResolvers.push(resolve);
            showLockscreen();
        });
    }

    /**
     * Public API: verify()
     * ALWAYS prompts for PIN/biometric, regardless of lock state.
     */
    function verify() {
        return new Promise(function(resolve) {
            if (!isActive()) {
                resolve(true);
                return;
            }
            pendingAuthResolvers.push(resolve);
            showLockscreen();
        });
    }

    function isLocked() {
        return locked;
    }

    // ============================================
    // VISIBILITY CHANGE — auto-lock
    // ============================================
    document.addEventListener('visibilitychange', function() {
        console.log('[AppLock] visibilitychange:', document.hidden ? 'hidden' : 'visible', 'isActive:', isActive(), 'lockscreenInjected:', lockscreenInjected);
        
        if (!isActive()) {
            console.log('[AppLock] Not active, ignoring visibility change');
            return;
        }

        // Re-inject lockscreen als beveiliging late is ingeschakeld (na page load)
        if (!lockscreenInjected) {
            injectLockscreen();
            console.log('[AppLock] Lockscreen injected on visibility change');
        }

        if (document.hidden) {
            console.log('[AppLock] Page hidden, starting background timer');
            backgroundTimestamp = Date.now();
        } else {
            if (backgroundTimestamp === null) {
                console.log('[AppLock] Page visible but no background timestamp, skipping');
                return;
            }

            var elapsed = (Date.now() - backgroundTimestamp) / 1000;
            backgroundTimestamp = null;
            console.log('[AppLock] Page visible, elapsed:', Math.round(elapsed), 'seconds, LOCK_TIMEOUT:', LOCK_TIMEOUT);

            // Always lock if app was in background, regardless of time
            // (The 60 second timeout is just a safety measure)
            if (isActive()) {
                console.log('[AppLock] Lock triggered! (app was backgrounded)');
                lock();
            } else {
                console.log('[AppLock] Not active, skipping lock');
            }
        }
    });

    // ============================================
    // INIT
    // ============================================
    function init() {
        // Check both localStorage AND server-side info
        var hasLocalPin = hasPin();
        var hasLocalWebAuthn = hasWebAuthn();
        
        console.log('[AppLock] Init: hasLocalPin=' + hasLocalPin + ', hasLocalWebAuthn=' + hasLocalWebAuthn);
        
        // If localStorage is empty but server says we have WebAuthn, restore it
        if (!hasLocalWebAuthn && window.REGULR && window.REGULR.sessionInfo && window.REGULR.sessionInfo.user && window.REGULR.sessionInfo.user.app_lock) {
            if (window.REGULR.sessionInfo.user.app_lock.has_webauthn) {
                console.log('[AppLock] Restoring WebAuthn flag from server');
                try { localStorage.setItem('regulr_webauthn_enabled', '1'); } catch(_) {}
                try { sessionStorage.setItem('regulr_webauthn_enabled', '1'); } catch(_) {}
            }
        }
        
        if (!isActive()) {
            console.log('[AppLock] Not active on init - hasPin:', hasPin(), 'hasWebAuthn:', hasWebAuthn());
            return;
        }

        try {
            failedAttempts = parseInt(sessionStorage.getItem('regulr_pin_fails') || '0', 10);
            if (isNaN(failedAttempts)) failedAttempts = 0;
        } catch (_) {
            failedAttempts = 0;
        }

        // Pre-inject lockscreen (verborgen)
        injectLockscreen();

        // ── LOCK ON FRESH APP OPEN ──
        // Alleen locken als deze tab/sessie NOOIT ontgrendeld is.
        // sessionStorage overleeft page-navigaties binnen dezelfde tab maar NIET een app-kill.
        // Bij: frisse PWA launch, app killed + heropend → geen flag → lock
        // Bij: pagina navigatie binnen app → flag staat → geen lock
        var alreadyUnlocked = false;
        try { alreadyUnlocked = sessionStorage.getItem('regulr_app_unlocked') === '1'; } catch(_) {}

        if (alreadyUnlocked) {
            console.log('[AppLock] Already unlocked this session — skip lock on init');
        } else {
            console.log('[AppLock] Fresh app open — locking');
            lock();
        }

        console.log('[AppLock] Initialized for guest - hasPin:', hasPin(), 'hasWebAuthn:', hasWebAuthn());
    }

    // ============================================
    // EXPORTS
    // ============================================
    window.REGULR = window.REGULR || {};
    window.REGULR.appLock = {
        init: init,
        isLocked: isLocked,
        requireAuth: requireAuth,
        verify: verify,
        lock: lock,
        unlock: unlock
    };

    // Auto-init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(init, 500);
        });
    } else {
        setTimeout(init, 500);
    }

})();
