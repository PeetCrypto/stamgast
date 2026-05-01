<?php
declare(strict_types=1);

/**
 * Bartender POS Dashboard
 * Unified scanner + payment in one view
 */

$firstName = $_SESSION['first_name'] ?? 'Bartender';
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="scanner-page" id="pos-page">

    <!-- ============ STATE: SCANNER ============ -->
    <div id="state-scanner">
        <div class="scanner-header">
            <span class="scanner-header__title">QR Scanner</span>
            <span class="nav-user">Hoi, <?= sanitize($firstName) ?></span>
            <button class="btn btn-ghost btn-sm" id="btn-logout" onclick="location.href=(window.__BASE_URL||'')+'/logout'">Uit</button>
        </div>

        <div class="scanner-viewport">
            <div id="qr-reader" style="width:100%;height:100%;"></div>
            <div class="scanner-frame"><span></span></div>
            <div class="scanner-line"></div>
        </div>

        <!-- Manual QR fallback -->
        <div style="padding:0.75rem 1rem;background:rgba(0,0,0,0.8);">
            <details>
                <summary style="color:var(--text-muted);font-size:13px;cursor:pointer;">Handmatige QR invoer</summary>
                <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                    <input type="text" id="manual-qr-input" class="form-input" placeholder="Plak QR code data..." style="font-size:13px;">
                    <button class="btn btn-primary btn-sm" id="manual-qr-btn" style="white-space:nowrap;">Verwerk</button>
                </div>
            </details>
        </div>
    </div>

    <!-- ============ STATE: PAYMENT ============ -->
    <div id="state-payment" style="display:none;">
        <div class="scanner-header">
            <button class="btn btn-ghost btn-sm" id="btn-back-scan">&larr; Terug</button>
            <span class="scanner-header__title">Betaling</span>
            <span></span>
        </div>

        <div class="payment-page" style="padding-bottom:1rem;">
            <!-- Scanned user info -->
            <div class="scan-result__avatar" style="text-align:center;margin-top:1rem;">
                <div class="avatar avatar--xl" id="pay-avatar" style="margin:0 auto;">
                    <div class="avatar__placeholder" id="pay-avatar-initial">?</div>
                </div>
            </div>
            <h2 class="scan-result__name" id="pay-user-name" style="text-align:center;margin-bottom:0.25rem;">-</h2>
            <div class="scan-result__badges" id="pay-badges" style="justify-content:center;margin-bottom:1.5rem;"></div>

            <!-- Amount inputs -->
            <div class="payment-amounts">
                <div class="payment-field glass-card" style="padding:1rem;">
                    <div class="payment-field__label">Alcohol</div>
                    <div class="payment-field__value" id="pay-alc-display">&euro; 0,00</div>
                    <div style="display:flex;gap:0.25rem;margin-top:0.5rem;flex-wrap:wrap;justify-content:center;">
                        <button class="btn btn-secondary btn-sm alc-quick" data-amount="400" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+4</button>
                        <button class="btn btn-secondary btn-sm alc-quick" data-amount="600" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+6</button>
                        <button class="btn btn-secondary btn-sm alc-quick" data-amount="800" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+8</button>
                        <button class="btn btn-secondary btn-sm alc-quick" data-amount="1000" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+10</button>
                    </div>
                </div>
                <div class="payment-field glass-card" style="padding:1rem;">
                    <div class="payment-field__label">Eten</div>
                    <div class="payment-field__value" id="pay-food-display">&euro; 0,00</div>
                    <div style="display:flex;gap:0.25rem;margin-top:0.5rem;flex-wrap:wrap;justify-content:center;">
                        <button class="btn btn-secondary btn-sm food-quick" data-amount="500" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+5</button>
                        <button class="btn btn-secondary btn-sm food-quick" data-amount="750" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+7.50</button>
                        <button class="btn btn-secondary btn-sm food-quick" data-amount="1000" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+10</button>
                        <button class="btn btn-secondary btn-sm food-quick" data-amount="1500" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+15</button>
                    </div>
                </div>
            </div>

            <!-- Manual amount input -->
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;">
                <div style="display:flex;gap:0.75rem;">
                    <div style="flex:1;">
                        <label style="font-size:12px;color:var(--text-muted);">Alcohol (&euro;)</label>
                        <input type="number" id="pay-alc-input" class="form-input" placeholder="0.00" step="0.01" min="0" style="text-align:center;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:12px;color:var(--text-muted);">Eten (&euro;)</label>
                        <input type="number" id="pay-food-input" class="form-input" placeholder="0.00" step="0.01" min="0" style="text-align:center;">
                    </div>
                </div>
                <button class="btn btn-ghost btn-sm" id="btn-clear-amounts" style="margin-top:0.5rem;width:auto;">Wissen</button>
            </div>

            <!-- Summary -->
            <div class="glass-card payment-summary" style="padding:1rem;margin-bottom:1rem;">
                <div class="payment-summary__row">
                    <span>Korting alcohol</span>
                    <span id="pay-disc-alc">-&euro;0,00</span>
                </div>
                <div class="payment-summary__row">
                    <span>Korting eten</span>
                    <span id="pay-disc-food">-&euro;0,00</span>
                </div>
                <div class="payment-summary__total">
                    <span>Totaal</span>
                    <span id="pay-total">&euro;0,00</span>
                </div>
                <div class="payment-summary__row" style="margin-top:0.5rem;">
                    <span>Saldo gast</span>
                    <span id="pay-balance">-</span>
                </div>
            </div>

            <!-- Pay button -->
            <button class="btn btn-primary" id="btn-pay" disabled style="font-size:18px;padding:1rem;">
                Betaling Verwerken
            </button>
        </div>
    </div>

    <!-- ============ STATE: VERIFY (Gated Onboarding) ============ -->
    <div id="state-verify" style="display:none;">
        <div class="scanner-header">
            <button class="btn btn-ghost btn-sm" id="btn-back-scan-verify">&larr; Terug</button>
            <span class="scanner-header__title">Identiteit Verifiëren</span>
            <span></span>
        </div>

        <div style="padding:1rem;">
            <!-- Scanned user info -->
            <div style="text-align:center;margin:1rem 0;">
                <div class="avatar avatar--xl" id="verify-avatar" style="margin:0 auto;">
                    <div class="avatar__placeholder" id="verify-avatar-initial">?</div>
                </div>
            </div>
            <h2 id="verify-user-name" style="text-align:center;margin-bottom:0.25rem;">-</h2>
            <p id="verify-status-badge" style="text-align:center;margin-bottom:1.5rem;">
                <span style="background:rgba(255,193,7,0.2);color:#FFC107;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;">NIET GEVERIFIEERD</span>
            </p>

            <!-- Instructions -->
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;background:rgba(255,193,7,0.08);border-color:rgba(255,193,7,0.3);">
                <p style="font-size:13px;color:var(--text-secondary);margin:0;">
                    Controleer het ID van de gast. Voer de geboortedatum in zoals op het ID staat.
                </p>
            </div>

            <!-- Birthdate input -->
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;">
                <label for="verify-birthdate" style="font-size:13px;color:var(--text-muted);display:block;margin-bottom:0.5rem;">Geboortedatum van ID</label>
                <input type="date" id="verify-birthdate" class="form-input" style="text-align:center;font-size:18px;" required>
            </div>

            <!-- Verify button -->
            <button class="btn btn-primary" id="btn-verify" style="font-size:18px;padding:1rem;width:100%;">
                Valideer & Activeer
            </button>

            <!-- Error display -->
            <div id="verify-error" style="display:none;margin-top:1rem;padding:1rem;border-radius:8px;background:rgba(244,67,54,0.1);border:1px solid rgba(244,67,54,0.3);">
                <p id="verify-error-msg" style="color:var(--error);font-size:14px;margin:0;"></p>
            </div>

            <!-- Success display -->
            <div id="verify-success" style="display:none;margin-top:1rem;padding:1rem;border-radius:8px;background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.3);">
                <p style="color:var(--success);font-size:14px;margin:0;">✓ Account geactiveerd! Doorsturen naar betaling...</p>
            </div>
        </div>
    </div>

    <!-- ============ STATE: SUCCESS ============ -->
    <div id="state-success" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:999;display:none;flex-direction:column;align-items:center;justify-content:center;padding:2rem;">
        <div style="font-size:64px;margin-bottom:1rem;">&#10003;</div>
        <h2 style="color:var(--success);margin-bottom:0.5rem;">Betaling gelukt!</h2>
        <p id="success-details" style="color:var(--text-secondary);margin-bottom:2rem;"></p>
        <button class="btn btn-primary" id="btn-next-scan">Volgende gast</button>
    </div>

    <!-- Alerts container -->
    <div class="alerts-container" id="pos-alerts"></div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function() {
    'use strict';

    // --- State ---
    let currentUser = null;
    let alcCents = 0;
    let foodCents = 0;
    let qrScanner = null;
    let processing = false;

    // --- Helpers ---
    function $(sel) { return document.querySelector(sel); }
    function $$(sel) { return document.querySelectorAll(sel); }

    function fmtCents(c) {
        return '\u20AC ' + (c / 100).toFixed(2).replace('.', ',');
    }

    function show(el) { if (typeof el === 'string') el = $(el); if (el) el.style.display = ''; }
    function hide(el) { if (typeof el === 'string') el = $(el); if (el) el.style.display = 'none'; }

    function switchState(state) {
        hide('#state-scanner');
        hide('#state-payment');
        hide('#state-verify');
        hide('#state-success');
        if (state === 'scanner') {
            show('#state-scanner');
            startScanner();
        } else if (state === 'payment') {
            stopScanner();
            show('#state-payment');
        } else if (state === 'verify') {
            stopScanner();
            show('#state-verify');
        } else if (state === 'success') {
            stopScanner();
            show('#state-success');
            $('#state-success').style.display = 'flex';
        }
    }

    function alert(msg, type) {
        type = type || 'error';
        const div = document.createElement('div');
        div.className = 'alert alert-' + type;
        div.textContent = msg;
        const c = $('#pos-alerts');
        if (c) {
            c.appendChild(div);
            setTimeout(function() { div.remove(); }, 4000);
        }
    }

    function getCSRF() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    // --- Scanner ---
    function startScanner() {
        if (qrScanner) return;
        try {
            qrScanner = new Html5Qrcode('qr-reader');
            qrScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                onQRDetected,
                function() {} // ignore scan failures
            ).catch(function(err) {
                console.error('Camera start error:', err);
                alert('Camera kon niet starten: ' + err);
            });
        } catch (e) {
            console.error('Html5Qrcode init error:', e);
            alert('QR scanner kon niet initialiseren. Gebruik handmatige invoer.');
        }
    }

    function stopScanner() {
        if (qrScanner) {
            try {
                qrScanner.stop().then(function() {
                    qrScanner.clear();
                    qrScanner = null;
                }).catch(function() {
                    qrScanner = null;
                });
            } catch (e) {
                qrScanner = null;
            }
        }
    }

    function onQRDetected(decodedText) {
        stopScanner();
        validateQR(decodedText);
    }

    // --- QR Validation ---
    function validateQR(payload) {
        fetch((window.__BASE_URL || '') + '/api/pos/scan', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ qr_payload: payload })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            // API returns { success: true, data: { valid, user } } or { success: false, error }
            var result = data.data || data;
            if (data.success && result.valid) {
                currentUser = result;
                var status = result.user.account_status || 'active';
                if (status === 'suspended') {
                    alert('Dit account is geblokkeerd door de beheerder');
                    startScanner();
                } else if (status === 'unverified' && result.verification_required !== false) {
                    showVerify();
                } else {
                    showPayment();
                }
            } else {
                alert(result.error || data.error || 'QR validatie mislukt');
                startScanner();
            }
        })
        .catch(function(e) {
            alert('Fout bij validatie: ' + e.message);
            startScanner();
        });
    }

    // --- Payment State ---
    function showPayment() {
        if (!currentUser || !currentUser.user) return;

        // User info — API returns user.name (combined), user.age, user.tier
        var u = currentUser.user;
        $('#pay-user-name').textContent = u.name || '-';

        var initial = (u.name || '?').charAt(0).toUpperCase();
        $('#pay-avatar-initial').textContent = initial;

        if (u.photo_url) {
            var img = document.createElement('img');
            img.src = u.photo_url;
            img.alt = 'Gast';
            var avatar = $('#pay-avatar');
            avatar.innerHTML = '';
            avatar.appendChild(img);
        }

        // Badges
        var badges = '';
        if (u.age >= 18) {
            badges += '<span class="badge-age badge-age--adult">18+</span>';
        } else {
            badges += '<span class="badge-age badge-age--minor">&lt;18</span>';
        }
        if (u.tier && u.tier.name) {
            badges += ' <span class="badge badge--gold">' + u.tier.name + '</span>';
        }
        $('#pay-badges').innerHTML = badges;

        // Reset amounts
        alcCents = 0;
        foodCents = 0;
        updatePaymentUI();

        switchState('payment');
    }

    // --- Verify State (Gated Onboarding) ---
    function showVerify() {
        if (!currentUser || !currentUser.user) return;
        var u = currentUser.user;
        $('#verify-user-name').textContent = u.name || '-';
        $('#verify-avatar-initial').textContent = (u.name || '?').charAt(0).toUpperCase();
        if (u.photo_url) {
            var img = document.createElement('img');
            img.src = u.photo_url;
            img.alt = 'Gast';
            var avatar = $('#verify-avatar');
            avatar.innerHTML = '';
            avatar.appendChild(img);
        }
        $('#verify-birthdate').value = '';
        hide('#verify-error');
        hide('#verify-success');
        var btn = $('#btn-verify');
        if (btn) btn.disabled = false;
        switchState('verify');
    }

    function verifyUser() {
        if (!currentUser || !currentUser.user) return;
        var birthdate = $('#verify-birthdate').value;
        if (!birthdate) {
            alert('Voer de geboortedatum in zoals op het ID staat');
            return;
        }
        var btn = $('#btn-verify');
        if (btn) btn.disabled = true;

        fetch((window.__BASE_URL || '') + '/api/pos/verify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ user_id: currentUser.user.id, birthdate: birthdate })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                currentUser.user.account_status = 'active';
                show('#verify-success');
                hide('#verify-error');
                setTimeout(function() { showPayment(); }, 1200);
            } else {
                hide('#verify-success');
                show('#verify-error');
                $('#verify-error-msg').textContent = data.error || 'Verificatie mislukt';
                if (btn) btn.disabled = false;
            }
        })
        .catch(function(e) {
            alert('Fout bij verificatie: ' + e.message);
            if (btn) btn.disabled = false;
        });
    }

    function updatePaymentUI() {
        var alcDiscount = 0;
        var foodDiscount = 0;

        if (currentUser && currentUser.user && currentUser.user.tier) {
            var tier = currentUser.user.tier;
            var alcPerc = Math.min(tier.alcohol_discount || 0, 25);
            var foodPerc = tier.food_discount || 0;
            alcDiscount = Math.floor(alcCents * alcPerc / 100);
            foodDiscount = Math.floor(foodCents * foodPerc / 100);
        }

        var alcFinal = alcCents - alcDiscount;
        var foodFinal = foodCents - foodDiscount;
        var total = alcFinal + foodFinal;

        $('#pay-alc-display').textContent = fmtCents(alcFinal);
        $('#pay-food-display').textContent = fmtCents(foodFinal);
        $('#pay-disc-alc').textContent = '-' + fmtCents(alcDiscount);
        $('#pay-disc-food').textContent = '-' + fmtCents(foodDiscount);
        $('#pay-total').textContent = fmtCents(total);

        // Balance check
        var balance = (currentUser && currentUser.user && currentUser.user.wallet) ? currentUser.user.wallet.balance_cents : 0;
        var balanceEl = $('#pay-balance');
        if (balanceEl) {
            balanceEl.textContent = fmtCents(balance);
            balanceEl.style.color = balance >= total ? 'var(--success)' : 'var(--error)';
        }

        // Pay button
        var payBtn = $('#btn-pay');
        if (payBtn) {
            payBtn.disabled = total <= 0 || !currentUser || balance < total || processing;
        }
    }

    // --- Quick amount buttons ---
    function setupQuickAmounts() {
        $$('.alc-quick').forEach(function(btn) {
            btn.addEventListener('click', function() {
                alcCents += parseInt(btn.dataset.amount, 10);
                var inp = $('#pay-alc-input');
                if (inp) inp.value = (alcCents / 100).toFixed(2);
                updatePaymentUI();
            });
        });

        $$('.food-quick').forEach(function(btn) {
            btn.addEventListener('click', function() {
                foodCents += parseInt(btn.dataset.amount, 10);
                var inp = $('#pay-food-input');
                if (inp) inp.value = (foodCents / 100).toFixed(2);
                updatePaymentUI();
            });
        });
    }

    function setupManualInputs() {
        var alcInput = $('#pay-alc-input');
        var foodInput = $('#pay-food-input');

        if (alcInput) {
            alcInput.addEventListener('input', function() {
                alcCents = Math.round((parseFloat(alcInput.value) || 0) * 100);
                updatePaymentUI();
            });
        }
        if (foodInput) {
            foodInput.addEventListener('input', function() {
                foodCents = Math.round((parseFloat(foodInput.value) || 0) * 100);
                updatePaymentUI();
            });
        }

        var clearBtn = $('#btn-clear-amounts');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                alcCents = 0;
                foodCents = 0;
                if (alcInput) alcInput.value = '';
                if (foodInput) foodInput.value = '';
                updatePaymentUI();
            });
        }
    }

    // --- Process payment ---
    function processPayment() {
        if (processing || !currentUser) return;

        var tier = (currentUser.user && currentUser.user.tier) ? currentUser.user.tier : null;
        var alcDiscount = Math.floor(alcCents * Math.min(tier ? tier.alcohol_discount : 0, 25) / 100);
        var foodDiscount = Math.floor(foodCents * (tier ? tier.food_discount : 0) / 100);
        var total = (alcCents - alcDiscount) + (foodCents - foodDiscount);

        if (total <= 0) {
            alert('Geen bedrag ingevoerd');
            return;
        }

        processing = true;
        var payBtn = $('#btn-pay');
        if (payBtn) payBtn.disabled = true;

        fetch((window.__BASE_URL || '') + '/api/pos/process_payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                user_id: currentUser.user.id,
                amount_alc_cents: alcCents,
                amount_food_cents: foodCents
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var d = data.data || data;
                $('#success-details').textContent =
                    fmtCents(d.final_total) + ' verwerkt \u2022 +' + (d.points_earned || 0) + ' punten';
                switchState('success');
            } else {
                throw new Error(data.error || 'Betaling mislukt');
            }
        })
        .catch(function(e) {
            alert('Betaling mislukt: ' + e.message);
            processing = false;
            if (payBtn) payBtn.disabled = false;
        });
    }

    // --- Manual QR input ---
    function setupManualQR() {
        var btn = $('#manual-qr-btn');
        var input = $('#manual-qr-input');
        if (!btn || !input) return;

        btn.addEventListener('click', function() {
            var val = input.value.trim();
            if (val) {
                stopScanner();
                validateQR(val);
                input.value = '';
            }
        });
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') btn.click();
        });
    }

    // --- Navigation ---
    function setupNav() {
        var backBtn = $('#btn-back-scan');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                currentUser = null;
                alcCents = 0;
                foodCents = 0;
                switchState('scanner');
            });
        }

        var nextBtn = $('#btn-next-scan');
        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                currentUser = null;
                alcCents = 0;
                foodCents = 0;
                processing = false;
                switchState('scanner');
            });
        }

        var payBtn = $('#btn-pay');
        if (payBtn) {
            payBtn.addEventListener('click', processPayment);
        }

        // Verify state buttons (Gated Onboarding)
        var verifyBtn = $('#btn-verify');
        if (verifyBtn) {
            verifyBtn.addEventListener('click', verifyUser);
        }
        var backScanVerify = $('#btn-back-scan-verify');
        if (backScanVerify) {
            backScanVerify.addEventListener('click', function() {
                currentUser = null;
                alcCents = 0;
                foodCents = 0;
                switchState('scanner');
            });
        }
    }

    // --- Init ---
    function init() {
        setupQuickAmounts();
        setupManualInputs();
        setupManualQR();
        setupNav();
        switchState('scanner');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
