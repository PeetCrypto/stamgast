/**
 * REGULR.vip - Security Setup Logic
 * Gast kiest: PIN-code, FaceID/Vingerafdruk, of beide
 * Stuurt de 3-fasen UI aan vanuit pin-setup.php
 */
(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    const PIN_LENGTH = 4;
    const BASE = window.__BASE_URL || '';

    // ============================================
    // GUARD: redirect als beveiliging al ingesteld
    // ============================================
    try {
        if (localStorage.getItem('regulr_pin_hash') || localStorage.getItem('regulr_webauthn_enabled') === '1') {
            window.location.href = BASE + '/dashboard';
            return;
        }
    } catch (_) {}

    // ============================================
    // STATE
    // ============================================
    let selectedChoice = null;   // 'pin' | 'faceid' | 'both'
    let pinStep = 1;            // 1 = eerste PIN, 2 = bevestig PIN
    let firstPin = '';
    let currentPin = '';
    let pinSet = false;

    // ============================================
    // DOM ELEMENTS
    // ============================================
    const phaseChoice = document.getElementById('phase-choice');
    const phasePin = document.getElementById('phase-pin');
    const phaseFaceid = document.getElementById('phase-faceid');
    const phaseSuccess = document.getElementById('phase-success');

    // Choice cards
    const choiceCards = document.querySelectorAll('.choice-card');
    const choiceFaceid = document.getElementById('choice-faceid');

    // PIN flow elements
    const dots = document.querySelectorAll('#pin-dots .pin-dot');
    const stepLabel = document.getElementById('step-label');
    const errorEl = document.getElementById('pin-error');
    const keypad = document.getElementById('pin-keypad');
    const webauthnBtn = document.getElementById('webauthn-btn');
    const pinDoneActions = document.getElementById('pin-done-actions');
    const pinBack = document.getElementById('pin-back');

    // FaceID flow elements
    const faceidRegisterBtn = document.getElementById('faceid-register-btn');
    const faceidError = document.getElementById('faceid-error');
    const faceidBack = document.getElementById('faceid-back');

    // Success
    const successDesc = document.getElementById('success-desc');

    // ============================================
    // HIDE FACEID OPTION if WebAuthn not available
    // ============================================
    if (!window.PublicKeyCredential || !window.isSecureContext) {
        if (choiceFaceid) {
            choiceFaceid.style.display = 'none';
        }
        // Also hide the "both" option since it requires WebAuthn
        const bothCard = document.querySelector('.choice-card[data-choice="both"]');
        if (bothCard) {
            bothCard.style.display = 'none';
        }
    }

    // ============================================
    // STANDALONE / PWA MODE DETECTION
    // Als NIET in PWA mode → toon waarschuwing bij FaceID opties
    // FaceID in browser mode kan Google Password Manager tonen i.p.v. native FaceID
    // ============================================
    var isStandalone = false;
    try {
        isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    } catch (_) {}

    if (!isStandalone) {
        // Toon PWA waarschuwingsbanner
        var pwaWarning = document.getElementById('faceid-pwa-warning');
        if (pwaWarning) pwaWarning.style.display = 'block';
    }

    // ============================================
    // PHASE MANAGEMENT
    // ============================================
    function showPhase(phase) {
        if (phaseChoice) phaseChoice.style.display = phase === 'choice' ? 'block' : 'none';
        if (phasePin) phasePin.style.display = phase === 'pin' ? 'block' : 'none';
        if (phaseFaceid) phaseFaceid.style.display = phase === 'faceid' ? 'block' : 'none';
        if (phaseSuccess) phaseSuccess.style.display = phase === 'success' ? 'block' : 'none';
    }

    function goToChoice() {
        selectedChoice = null;
        choiceCards.forEach(function(c) { c.classList.remove('selected'); });
        showPhase('choice');
    }

    // ============================================
    // CHOICE CARD CLICK HANDLERS
    // ============================================
    choiceCards.forEach(function(card) {
        card.addEventListener('click', function() {
            selectedChoice = card.dataset.choice;

            switch (selectedChoice) {
                case 'pin':
                    resetPinFlow();
                    showPhase('pin');
                    // Hide webauthn button and show pin-done for pin-only flow
                    if (webauthnBtn) webauthnBtn.style.display = 'none';
                    if (pinDoneActions) pinDoneActions.style.display = 'none';
                    break;

                case 'faceid':
                    showPhase('faceid');
                    if (faceidError) { faceidError.textContent = ''; faceidError.style.display = 'none'; }
                    break;

                case 'both':
                    resetPinFlow();
                    showPhase('pin');
                    // Will show webauthn button after PIN is set
                    if (webauthnBtn) webauthnBtn.style.display = 'none';
                    if (pinDoneActions) pinDoneActions.style.display = 'none';
                    break;
            }
        });
    });

    // ============================================
    // BACK BUTTONS
    // ============================================
    if (pinBack) pinBack.addEventListener('click', goToChoice);
    if (faceidBack) faceidBack.addEventListener('click', goToChoice);

    // ============================================
    // PIN HASH (SHA-256, client-side only)
    // ============================================
    async function hashPin(pin) {
        if (window.crypto && crypto.subtle) {
            try {
                const encoder = new TextEncoder();
                const data = encoder.encode(pin + '__regulr_salt__');
                const hashBuffer = await crypto.subtle.digest('SHA-256', data);
                return Array.from(new Uint8Array(hashBuffer))
                    .map(b => b.toString(16).padStart(2, '0'))
                    .join('');
            } catch (e) {
                console.warn('Web Crypto not available, using fallback hash');
            }
        }
        return fallbackHash(pin + '__regulr_salt__');
    }

    function fallbackHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const chr = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + chr;
            hash |= 0;
        }
        return Math.abs(hash).toString(16).padStart(8, '0') +
               Math.abs(hash * 31).toString(16).padStart(8, '0');
    }

    // ============================================
    // PIN FLOW UI
    // ============================================
    function resetPinFlow() {
        pinStep = 1;
        firstPin = '';
        currentPin = '';
        pinSet = false;
        if (stepLabel) stepLabel.textContent = 'Stap 1 — Kies je PIN';
        updateDots();
        clearPinError();
    }

    function updateDots() {
        dots.forEach((dot, i) => {
            dot.classList.remove('filled', 'error', 'success');
            if (i < currentPin.length) {
                dot.classList.add('filled');
            }
        });
    }

    function showPinError(message) {
        if (!errorEl) return;
        errorEl.textContent = message;
        errorEl.style.display = 'block';
        dots.forEach(d => d.classList.add('error'));
        setTimeout(() => {
            dots.forEach(d => d.classList.remove('error'));
        }, 600);
    }

    function clearPinError() {
        if (!errorEl) return;
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }

    function showPinSuccess() {
        dots.forEach(d => {
            d.classList.remove('filled');
            d.classList.add('success');
        });
    }

    // ============================================
    // PIN INPUT HANDLING
    // ============================================
    function handleDigit(digit) {
        if (pinSet) return;
        clearPinError();

        if (digit === 'backspace') {
            currentPin = currentPin.slice(0, -1);
            updateDots();
            return;
        }

        if (digit === '' || currentPin.length >= PIN_LENGTH) return;

        currentPin += digit;
        updateDots();

        if (currentPin.length === PIN_LENGTH) {
            setTimeout(() => processPin(), 250);
        }
    }

    async function processPin() {
        if (pinStep === 1) {
            firstPin = currentPin;
            currentPin = '';
            pinStep = 2;
            stepLabel.textContent = 'Stap 2 — Bevestig je PIN';
            updateDots();
        } else if (pinStep === 2) {
            if (currentPin === firstPin) {
                // PIN match — sla op
                const hash = await hashPin(currentPin);
                try {
                    localStorage.setItem('regulr_pin_hash', hash);
                    localStorage.setItem('regulr_pin_set_at', new Date().toISOString());
                } catch (e) {
                    showPinError('Kon PIN niet opslaan. Probeer opnieuw.');
                    resetPinFlow();
                    return;
                }

                pinSet = true;
                showPinSuccess();
                stepLabel.textContent = 'PIN ingesteld ✅';

                if (selectedChoice === 'both') {
                    // Toon WebAuthn knop voor tweede stap
                    if (webauthnBtn && window.PublicKeyCredential && window.isSecureContext) {
                        webauthnBtn.style.display = 'block';
                    }
                    // Toon skip link
                    if (pinDoneActions) pinDoneActions.style.display = 'block';
                } else {
                    // PIN-only — toon ga naar dashboard
                    if (pinDoneActions) pinDoneActions.style.display = 'block';
                }
            } else {
                showPinError('PIN komt niet overeen. Probeer opnieuw.');
                pinStep = 1;
                firstPin = '';
                currentPin = '';
                setTimeout(() => {
                    updateDots();
                    stepLabel.textContent = 'Stap 1 — Kies je PIN';
                }, 600);
            }
        }
    }

    // Keypad click handler
    if (keypad) {
        keypad.addEventListener('click', function(e) {
            const btn = e.target.closest('.pin-key');
            if (!btn) return;
            handleDigit(btn.dataset.digit);
        });
    }

    // Keyboard input (desktop testing)
    document.addEventListener('keydown', function(e) {
        if (pinSet) return;
        if (!phasePin || phasePin.style.display === 'none') return;

        if (e.key >= '0' && e.key <= '9') {
            handleDigit(e.key);
        } else if (e.key === 'Backspace') {
            handleDigit('backspace');
        }
    });

    // ============================================
    // WEBAUTHN REGISTRATION
    // ============================================
    function base64urlToArrayBuffer(base64url) {
        let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4 !== 0) base64 += '=';
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function arrayBufferToBase64url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    async function tryWebAuthnRegistration() {
        if (!window.PublicKeyCredential) {
            throw new Error('WebAuthn niet ondersteund op dit apparaat');
        }

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : '';

        // 1. Vraag registration options aan
        const optionsResp = await fetch(BASE + '/api/auth/webauthn/register-options', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            }
        });

        const optionsData = await optionsResp.json();
        if (!optionsData.success) {
            throw new Error(optionsData.error || 'Kon registratie niet starten');
        }

        // Als al geregistreerd, verifieer via authenticate flow (toont FaceID prompt)
        if (optionsData.data && optionsData.data.already_registered) {
            try {
                var authResp = await fetch(BASE + '/api/auth/webauthn/authenticate-options', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
                });
                var authData = await authResp.json();
                if (!authData.success) throw new Error('Failed');
                var pubKey = {
                    challenge: base64urlToArrayBuffer(authData.data.challenge),
                    rpId: authData.data.rpId,
                    allowCredentials: (authData.data.allowCredentials || []).map(function(c) {
                        return { type: c.type, id: base64urlToArrayBuffer(c.id), transports: c.transports || ['internal'] };
                    }),
                    timeout: authData.data.timeout || 60000,
                    userVerification: authData.data.userVerification || 'required'
                };
                await navigator.credentials.get({ publicKey: pubKey });
                // FaceID werkte!
                try { localStorage.setItem('regulr_webauthn_enabled', '1'); } catch(_) {}
            } catch (e) {
                console.warn('FaceID verification cancelled:', e.message);
            }
            return;
        }

        const options = optionsData.data;

        // 2. Converteer base64url naar ArrayBuffer
        const publicKey = {
            ...options,
            challenge: base64urlToArrayBuffer(options.challenge),
            user: {
                ...options.user,
                id: base64urlToArrayBuffer(options.user.id)
            },
            excludeCredentials: (options.excludeCredentials || []).map(function(c) {
                return {
                    ...c,
                    id: base64urlToArrayBuffer(c.id)
                };
            })
        };

        // 3. Start biometric prompt
        const credential = await navigator.credentials.create({ publicKey });

        // 4. Stuur response naar server
        const responseBody = {
            id: credential.id,
            rawId: arrayBufferToBase64url(credential.rawId),
            response: {
                clientDataJSON: arrayBufferToBase64url(credential.response.clientDataJSON),
                attestationObject: arrayBufferToBase64url(credential.response.attestationObject)
            },
            type: credential.type
        };

        const verifyResp = await fetch(BASE + '/api/auth/webauthn/register', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(responseBody)
        });

        const result = await verifyResp.json();
        if (!result.success) {
            throw new Error(result.error || 'Registratieverificatie mislukt');
        }

        // 5. Sla WebAuthn flag op
        try {
            localStorage.setItem('regulr_webauthn_enabled', '1');
        } catch (_) {}
    }

    // WebAuthn button in "both" flow (after PIN is set)
    if (webauthnBtn) {
        webauthnBtn.addEventListener('click', async function() {
            webauthnBtn.disabled = true;
            webauthnBtn.innerHTML = '<span class="icon">⏳</span>Bezig met registreren...';

            try {
                await tryWebAuthnRegistration();
                webauthnBtn.innerHTML = '<span class="icon">✅</span>FaceID / Vingerafdruk ingeschakeld';
                webauthnBtn.style.borderColor = 'rgba(76,175,80,0.4)';

                // Toon success
                setTimeout(() => {
                    showSuccess('PIN-code en FaceID/Vingerafdruk zijn ingesteld. Je app wordt automatisch vergrendeld na 60 seconden op de achtergrond.');
                }, 800);
            } catch (e) {
                console.warn('WebAuthn registration failed:', e.message);
                webauthnBtn.disabled = false;
                webauthnBtn.innerHTML = '<span class="icon">👤</span>FaceID / Vingerafdruk inschakelen';
                // PIN is al opgeslagen, niet kritiek
            }
        });
    }

    // FaceID-only register button
    if (faceidRegisterBtn) {
        faceidRegisterBtn.addEventListener('click', async function() {
            faceidRegisterBtn.disabled = true;
            faceidRegisterBtn.innerHTML = '<span class="icon">⏳</span>Bezig met registreren...';
            if (faceidError) { faceidError.textContent = ''; faceidError.style.display = 'none'; }

            try {
                await tryWebAuthnRegistration();

                // Success → ga naar success scherm
                showSuccess('FaceID/Vingerafdruk is ingesteld. Je app wordt automatisch vergrendeld na 60 seconden op de achtergrond.');
            } catch (e) {
                console.warn('WebAuthn registration failed:', e.message);
                faceidRegisterBtn.disabled = false;
                faceidRegisterBtn.innerHTML = '<span class="icon">👤</span>Registreer FaceID / Vingerafdruk';
                if (faceidError) {
                    faceidError.textContent = e.message || 'Kon FaceID niet registreren. Probeer het opnieuw.';
                    faceidError.style.display = 'block';
                }
            }
        });
    }

    // ============================================
    // SUCCESS
    // ============================================
    function showSuccess(message) {
        if (successDesc) successDesc.textContent = message;
        showPhase('success');
    }

})();
