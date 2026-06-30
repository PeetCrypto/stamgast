<?php
declare(strict_types=1);

/**
 * Bartender POS Dashboard — NEW FLOW
 * 1. Bartender enters amounts (alcohol + food)
 * 2. Generates QR code with payment session
 * 3. Shows QR on screen for guest to scan
 * 4. Polls session status → big ✅ or ❌
 */

$firstName = $_SESSION['first_name'] ?? 'Bartender';
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<!-- QR code wordt nu server-side gegenereerd als PNG (zelfde methode als join QR op /admin/settings) -->

<div class="scanner-page" id="pos-page">

    <!-- ============ STATE: AMOUNT ENTRY ============ -->
    <div id="state-amount">
        <div class="scanner-header">
            <span class="scanner-header__title">Kassa</span>
            <span class="nav-user">Hoi, <?= sanitize($firstName) ?></span>
            <button class="btn btn-ghost btn-sm" id="btn-logout" onclick="location.href=(window.__BASE_URL||'')+'/logout'">Uit</button>
        </div>

         <div class="payment-page" style="padding:1rem;">
            <!-- Alcohol amount -->
            <div class="payment-field glass-card" style="padding:1rem;margin-bottom:0.75rem;">
                <div class="payment-field__label">Alcohol (21%)</div>
                <input type="text" id="alc-input" inputmode="decimal" class="form-input" placeholder="0,00" data-amount-input
                    style="text-align:center;font-size:28px;font-weight:700;color:var(--accent-primary);background:transparent;border:1px dashed var(--border-color);border-radius:8px;padding:0.25rem 0.5rem;width:100%;">
                <div style="display:flex;gap:0.25rem;margin-top:0.5rem;flex-wrap:wrap;justify-content:center;">
                    <button class="btn btn-secondary btn-sm alc-quick" data-amount="400" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+4</button>
                    <button class="btn btn-secondary btn-sm alc-quick" data-amount="600" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+6</button>
                    <button class="btn btn-secondary btn-sm alc-quick" data-amount="800" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+8</button>
                    <button class="btn btn-secondary btn-sm alc-quick" data-amount="1000" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+10</button>
                </div>
            </div>

            <!-- Food amount -->
            <div class="payment-field glass-card" style="padding:1rem;margin-bottom:0.75rem;">
                <div class="payment-field__label">Non-Alcohol (9%)</div>
                <input type="text" id="food-input" inputmode="decimal" class="form-input" placeholder="0,00" data-amount-input
                    style="text-align:center;font-size:28px;font-weight:700;color:var(--accent-primary);background:transparent;border:1px dashed var(--border-color);border-radius:8px;padding:0.25rem 0.5rem;width:100%;">
                <div style="display:flex;gap:0.25rem;margin-top:0.5rem;flex-wrap:wrap;justify-content:center;">
                    <button class="btn btn-secondary btn-sm food-quick" data-amount="500" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+5</button>
                    <button class="btn btn-secondary btn-sm food-quick" data-amount="750" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+7,50</button>
                    <button class="btn btn-secondary btn-sm food-quick" data-amount="1000" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+10</button>
                    <button class="btn btn-secondary btn-sm food-quick" data-amount="1500" style="width:auto;padding:0.35rem 0.6rem;font-size:13px;">+15</button>
                </div>
            </div>

            <!-- Total + Clear -->
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;text-align:center;">
                <div style="font-size:13px;color:var(--text-muted);">Totaal</div>
                <div style="font-size:32px;font-weight:700;color:var(--accent-primary);" id="total-display">&euro; 0,00</div>
                <button class="btn btn-ghost btn-sm" id="btn-clear-amounts" style="margin-top:0.5rem;width:auto;">Wissen</button>
            </div>

            <!-- Generate QR button -->
            <button class="btn btn-primary" id="btn-generate-qr" disabled style="font-size:18px;padding:1rem;width:100%;">
                Genereer QR Code
            </button>
        </div>
    </div>

    <!-- ============ STATE: SHOW QR (waiting for guest) ============ -->
    <div id="state-qr" style="display:none;">
        <div class="scanner-header">
            <button class="btn btn-ghost btn-sm" id="btn-back-amount">&larr; Terug</button>
            <span class="scanner-header__title">Laat gast scannen</span>
            <span></span>
        </div>

        <div style="padding:1rem;text-align:center;">
            <!-- Amount summary -->
            <div class="glass-card" style="padding:0.75rem;margin-bottom:1rem;">
                <div style="font-size:14px;color:var(--text-muted);">Te betalen</div>
                <div style="font-size:28px;font-weight:700;color:var(--accent-primary);" id="qr-total">&euro; 0,00</div>
            </div>

            <!-- QR Code display (BIG) — server-side PNG, zelfde methode als join QR -->
            <div class="glass-card" style="padding:1.5rem;margin-bottom:1rem;display:inline-block;">
                <img id="qr-image" src="" alt="QR Code" style="width:280px;height:280px;display:none;margin:0 auto;">
                <div id="qr-loading" style="width:280px;height:280px;display:flex;align-items:center;justify-content:center;">
                    <div class="spinner"></div>
                </div>
            </div>

            <p style="color:var(--text-secondary);font-size:14px;margin-bottom:1rem;">
                Laat de gast deze QR code scannen met hun telefoon
            </p>

            <!-- Spinner + waiting status -->
            <div id="waiting-spinner" style="margin:1rem 0;">
                <div class="spinner" style="margin:0 auto 0.5rem;"></div>
                <p style="color:var(--text-muted);font-size:13px;">Wachten op gast...</p>
            </div>

            <!-- Countdown timer -->
            <div style="font-size:12px;color:var(--text-muted);margin-top:0.5rem;">
                <span id="qr-countdown">5:00</span> resterend
            </div>
        </div>
    </div>

    <!-- ============ STATE: SUCCESS ============ -->
    <div id="state-success" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.95);flex-direction:column;align-items:center;justify-content:center;padding:2rem;">
        <!-- Confetti canvas -->
        <canvas id="confetti-canvas" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1;"></canvas>
        <!-- Golden glow flash -->
        <div id="tip-glow" style="display:none;position:absolute;inset:0;pointer-events:none;z-index:0;
            box-shadow:inset 0 0 120px 40px rgba(255,215,0,0.25);animation:tipGlowFade 1.8s ease-out forwards;"></div>

        <div style="position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;">
            <div style="font-size:120px;line-height:1;color:#4CAF50;font-weight:900;">&#10003;</div>
            <h2 style="color:#4CAF50;font-size:32px;margin-bottom:0.5rem;">BETALING GELUKT!</h2>
            <p id="success-amount" style="color:var(--text-secondary);font-size:24px;font-weight:600;margin-bottom:0.25rem;"></p>

            <!-- FOOI CELEBRATION BADGE -->
            <div id="tip-badge" style="display:none;margin:0.75rem 0 0.25rem;text-align:center;">
                <div style="font-size:14px;color:#FFD700;text-transform:uppercase;letter-spacing:3px;font-weight:700;margin-bottom:0.15rem;">Fooi</div>
                <div id="tip-amount" style="font-size:42px;font-weight:800;color:#FFD700;text-shadow:0 0 30px rgba(255,215,0,0.5),0 0 60px rgba(255,215,0,0.25);"></div>
                <div style="font-size:20px;margin-top:0.35rem;">🎉</div>
            </div>

            <p id="success-guest" style="color:var(--text-muted);font-size:16px;margin-bottom:2rem;"></p>
            <button class="btn btn-primary" id="btn-next-guest" style="font-size:18px;padding:1rem 2rem;">Volgende gast</button>
        </div>
    </div>

    <style>
        @keyframes tipBadgeIn {
            0%   { opacity:0; transform:scale(0.2); }
            50%  { opacity:1; transform:scale(1.15); }
            70%  { transform:scale(0.95); }
            100% { opacity:1; transform:scale(1); }
        }
        @keyframes tipGlowFade {
            0%   { opacity:1; }
            100% { opacity:0; }
        }
    </style>

    <!-- ============ STATE: FAILED ============ -->
    <div id="state-failed" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.95);flex-direction:column;align-items:center;justify-content:center;padding:2rem;">
        <div style="font-size:120px;line-height:1;">&#10060;</div>
        <h2 style="color:#F44336;font-size:32px;margin-bottom:0.5rem;">BETALING MISLUKT</h2>
        <p id="fail-reason" style="color:var(--text-secondary);font-size:16px;margin-bottom:2rem;max-width:300px;text-align:center;"></p>
        <button class="btn btn-primary" id="btn-retry" style="font-size:18px;padding:1rem 2rem;">Opnieuw</button>
    </div>

    <!-- ============ STATE: VERIFY (Gated Onboarding — kept for identity verification) ============ -->
    <div id="state-verify" style="display:none;">
        <div class="scanner-header">
            <button class="btn btn-ghost btn-sm" id="btn-back-scan-verify">&larr; Terug</button>
            <span class="scanner-header__title">Identiteit Verifiëren</span>
            <span></span>
        </div>
        <div style="padding:1rem;">
            <div style="text-align:center;margin:1rem 0;">
                <div class="avatar avatar--xl" id="verify-avatar" style="margin:0 auto;">
                    <div class="avatar__placeholder" id="verify-avatar-initial">?</div>
                </div>
            </div>
            <h2 id="verify-user-name" style="text-align:center;margin-bottom:0.25rem;">-</h2>
            <p id="verify-status-badge" style="text-align:center;margin-bottom:1.5rem;">
                <span style="background:rgba(255,193,7,0.2);color:#FFC107;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;">NIET GEVERIFIEERD</span>
            </p>
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;background:rgba(255,193,7,0.08);border-color:rgba(255,193,7,0.3);">
                <p style="font-size:13px;color:var(--text-secondary);margin:0;">Controleer het ID van de gast. Voer de geboortedatum in zoals op het ID staat.</p>
            </div>
            <div class="glass-card" style="padding:1rem;margin-bottom:1rem;">
                <label for="verify-birthdate" style="font-size:13px;color:var(--text-muted);display:block;margin-bottom:0.5rem;">Geboortedatum van ID</label>
                <input type="date" id="verify-birthdate" class="form-input" style="text-align:center;font-size:18px;" required>
            </div>
            <button class="btn btn-primary" id="btn-verify" style="font-size:18px;padding:1rem;width:100%;">Valideer & Activeer</button>
            <div id="verify-error" style="display:none;margin-top:1rem;padding:1rem;border-radius:8px;background:rgba(244,67,54,0.1);border:1px solid rgba(244,67,54,0.3);">
                <p id="verify-error-msg" style="color:var(--error);font-size:14px;margin:0;"></p>
            </div>
            <div id="verify-success" style="display:none;margin-top:1rem;padding:1rem;border-radius:8px;background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.3);">
                <p style="color:var(--success);font-size:14px;margin:0;">&#10003; Account geactiveerd!</p>
            </div>
        </div>
    </div>

    <!-- Alerts container -->
    <div class="alerts-container" id="pos-alerts"></div>
</div>

<script>
(function() {
    'use strict';

    // --- State ---
    let alcCents = 0;
    let foodCents = 0;
    let currentSessionToken = null;
    let pollTimer = null;
    let countdownTimer = null;
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
        hide('#state-amount');
        hide('#state-qr');
        hide('#state-success');
        hide('#state-failed');
        hide('#state-verify');
        $('#state-success').style.display = 'none';
        $('#state-failed').style.display = 'none';

        if (state === 'amount') {
            show('#state-amount');
        } else if (state === 'qr') {
            show('#state-qr');
        } else if (state === 'success') {
            var el = $('#state-success');
            el.style.display = 'flex';
        } else if (state === 'failed') {
            var el = $('#state-failed');
            el.style.display = 'flex';
        } else if (state === 'verify') {
            show('#state-verify');
        }
    }

    function alert(msg, type) {
        type = type || 'error';
        var div = document.createElement('div');
        div.className = 'alert alert-' + type;
        div.textContent = msg;
        var c = $('#pos-alerts');
        if (c) {
            c.appendChild(div);
            setTimeout(function() { div.remove(); }, 4000);
        }
    }

    function getCSRF() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    // --- Amount UI ---
    function updateAmountUI() {
        var total = alcCents + foodCents;
        $('#total-display').textContent = fmtCents(total);
        $('#btn-generate-qr').disabled = total <= 0;
    }

    /**
     * Read current value from an input field and sync to cents.
     * Accepts BOTH Dutch (4,00) and English (4.00) notation, even mixed (4.000,00).
     */
    function readInputToCents(inputEl) {
        var raw = (inputEl.value || '').trim();
        if (!raw) return 0;
        // Strip everything except digits, comma and dot
        raw = raw.replace(/[^0-9,.]/g, '');
        if (!raw) return 0;
        var normalized;
        if (raw.indexOf(',') > -1 && raw.indexOf('.') > -1) {
            // Both present: last separator = decimal, others = thousands
            if (raw.lastIndexOf(',') > raw.lastIndexOf('.')) {
                // comma is decimal -> remove dots, replace comma with dot
                normalized = raw.replace(/\./g, '').replace(',', '.');
            } else {
                // dot is decimal -> remove commas
                normalized = raw.replace(/,/g, '');
            }
        } else if (raw.indexOf(',') > -1) {
            // Only comma: treat as decimal separator (Dutch)
            normalized = raw.replace(',', '.');
        } else {
            // Only dot or plain digits
            normalized = raw;
        }
        var euros = parseFloat(normalized);
        if (isNaN(euros) || euros < 0) return 0;
        return Math.round(euros * 100);
    }

    /** Write cents value to an input field in Dutch format (comma as decimal). */
    function writeCentsToInput(inputEl, cents) {
        inputEl.value = (cents / 100).toFixed(2).replace('.', ',');
    }

    function setupQuickAmounts() {
        $$('.alc-quick').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var alcInput = $('#alc-input');
                // Read current input value first (user may have typed manually)
                alcCents = readInputToCents(alcInput);
                // Add the quick-add amount
                alcCents += parseInt(btn.dataset.amount, 10);
                writeCentsToInput(alcInput, alcCents);
                updateAmountUI();
            });
        });
        $$('.food-quick').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var foodInput = $('#food-input');
                // Read current input value first (user may have typed manually)
                foodCents = readInputToCents(foodInput);
                // Add the quick-add amount
                foodCents += parseInt(btn.dataset.amount, 10);
                writeCentsToInput(foodInput, foodCents);
                updateAmountUI();
            });
        });
    }

    function setupManualInputs() {
        var alcInput = $('#alc-input');
        var foodInput = $('#food-input');
        if (alcInput) {
            alcInput.addEventListener('input', function() {
                alcCents = readInputToCents(alcInput);
                updateAmountUI();
            });
            // Normalize to Dutch comma format when leaving the field
            alcInput.addEventListener('blur', function() {
                alcCents = readInputToCents(alcInput);
                writeCentsToInput(alcInput, alcCents);
                updateAmountUI();
            });
        }
        if (foodInput) {
            foodInput.addEventListener('input', function() {
                foodCents = readInputToCents(foodInput);
                updateAmountUI();
            });
            // Normalize to Dutch comma format when leaving the field
            foodInput.addEventListener('blur', function() {
                foodCents = readInputToCents(foodInput);
                writeCentsToInput(foodInput, foodCents);
                updateAmountUI();
            });
        }
        var clearBtn = $('#btn-clear-amounts');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                alcCents = 0;
                foodCents = 0;
                if (alcInput) alcInput.value = '';
                if (foodInput) foodInput.value = '';
                updateAmountUI();
            });
        }
    }

    // --- Generate QR (create session) ---
    function generateQR() {
        if (processing) return;
        if (alcCents <= 0 && foodCents <= 0) {
            alert('Voer een bedrag in');
            return;
        }
        processing = true;
        var btn = $('#btn-generate-qr');
        if (btn) btn.disabled = true;

        fetch((window.__BASE_URL || '') + '/api/pos/create_session', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                amount_alc_cents: alcCents,
                amount_food_cents: foodCents
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            processing = false;
            if (data.success) {
                var d = data.data;
                currentSessionToken = d.session_token;
                $('#qr-total').textContent = fmtCents(alcCents + foodCents);

                // Render QR code — try base64 first, then URL, then fallback
                renderQR(d.qr_png_base64, d.qr_png_url, d.qr_data);

                // Show QR state
                switchState('qr');

                // Start polling
                startPolling();

                // Start countdown
                startCountdown(d.expires_at);
            } else {
                alert('QR code kon niet worden gegenereerd');
                if (btn) btn.disabled = false;
            }
        })
        .catch(function(e) {
            processing = false;
            alert('Er is een fout opgetreden');
            if (btn) btn.disabled = false;
        });
    }

    function renderQR(base64, pngUrl, fallbackData) {
        var img = document.getElementById('qr-image');
        var loading = document.getElementById('qr-loading');
        if (!img) return;

        // Show loading spinner
        if (loading) loading.style.display = 'flex';
        img.style.display = 'none';

        img.onload = function() {
            if (loading) loading.style.display = 'none';
            img.style.display = 'block';
        };
        img.onerror = function() {
            // Try next fallback
            if (pngUrl && !img.dataset.triedUrl) {
                img.dataset.triedUrl = '1';
                img.src = pngUrl;
            } else {
                if (loading) loading.style.display = 'none';
                img.style.display = 'none';
                var container = img.parentElement;
                container.innerHTML = '<div style="padding:1rem;color:var(--error);text-align:center;">QR kon niet laden</div>';
            }
        };

        // Priority: base64 data URI > png URL
        if (base64 && base64.length > 100) {
            img.src = base64;
        } else if (pngUrl) {
            img.src = pngUrl;
        } else {
            img.onerror();
        }
        delete img.dataset.triedUrl;
    }

    // --- Polling ---
    function startPolling() {
        stopPolling();
        pollTimer = setInterval(function() {
            if (!currentSessionToken) return;
            fetch((window.__BASE_URL || '') + '/api/pos/session_status?session_token=' + encodeURIComponent(currentSessionToken), {
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;
                var status = data.data.status;
                if (status === 'confirmed') {
                    stopPolling();
                    stopCountdown();
                    showSuccess(data.data);
                } else if (status === 'failed' || status === 'cancelled' || status === 'expired') {
                    stopPolling();
                    stopCountdown();
                    showFailed(data.data.error_message || 'Betaling mislukt');
                }
            })
            .catch(function() {});
        }, 2000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // --- Countdown ---
    function startCountdown(expiresAt) {
        stopCountdown();
        var el = $('#qr-countdown');
        countdownTimer = setInterval(function() {
            var now = Math.floor(Date.now() / 1000);
            var remaining = expiresAt - now;
            if (remaining <= 0) {
                stopCountdown();
                stopPolling();
                if (el) el.textContent = '0:00';
                showFailed('QR code verlopen');
                return;
            }
            var min = Math.floor(remaining / 60);
            var sec = remaining % 60;
            if (el) el.textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
        }, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    // --- Confetti celebration (no external library) ---
    function launchConfetti() {
        var canvas = document.getElementById('confetti-canvas');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        var particles = [];
        var colors = ['#FFD700','#FFA500','#FF6347','#4CAF50','#FFFFFF','#FF69B4','#00CED1'];
        var totalFrames = 180;
        var frame = 0;

        for (var i = 0; i < 120; i++) {
            var angle = Math.random() * Math.PI * 2;
            var speed = 4 + Math.random() * 8;
            particles.push({
                x: canvas.width / 2,
                y: canvas.height / 2,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed - 3,
                size: 3 + Math.random() * 5,
                color: colors[Math.floor(Math.random() * colors.length)],
                rotation: Math.random() * 360,
                rotSpeed: (Math.random() - 0.5) * 12,
                gravity: 0.12 + Math.random() * 0.08,
                opacity: 1,
                shape: Math.random() > 0.5 ? 'rect' : 'circle'
            });
        }

        function tick() {
            if (frame >= totalFrames) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                return;
            }
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                p.x += p.vx;
                p.vy += p.gravity;
                p.y += p.vy;
                p.vx *= 0.99;
                p.rotation += p.rotSpeed;
                p.opacity = Math.max(0, 1 - (frame / totalFrames));

                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rotation * Math.PI / 180);
                ctx.globalAlpha = p.opacity;
                ctx.fillStyle = p.color;

                if (p.shape === 'rect') {
                    ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                } else {
                    ctx.beginPath();
                    ctx.arc(0, 0, p.size / 2, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.restore();
            }

            frame++;
            requestAnimationFrame(tick);
        }
        tick();
    }

    // --- Success / Failed ---
    function showSuccess(data) {
        $('#success-amount').textContent = fmtCents(data.final_total_cents || 0);
        $('#success-guest').textContent = data.guest_name || '';

        var tipCents = data.tip_cents || 0;
        var tipBadge = document.getElementById('tip-badge');
        var tipGlow = document.getElementById('tip-glow');

        if (tipCents > 0 && tipBadge) {
            document.getElementById('tip-amount').textContent = fmtCents(tipCents);
            tipBadge.style.display = 'block';
            tipBadge.style.animation = 'tipBadgeIn 0.6s cubic-bezier(0.34,1.56,0.64,1) forwards';
            if (tipGlow) tipGlow.style.display = 'block';
            launchConfetti();
        } else {
            if (tipBadge) { tipBadge.style.display = 'none'; tipBadge.style.animation = ''; }
            if (tipGlow) tipGlow.style.display = 'none';
        }

        switchState('success');
    }

    function showFailed(reason) {
        $('#fail-reason').textContent = reason || 'Onbekende fout';
        switchState('failed');
    }

    // --- Reset ---
    function resetAll() {
        stopPolling();
        stopCountdown();
        alcCents = 0;
        foodCents = 0;
        currentSessionToken = null;
        processing = false;
        var alcInput = $('#alc-input');
        var foodInput = $('#food-input');
        if (alcInput) alcInput.value = '';
        if (foodInput) foodInput.value = '';
        updateAmountUI();
        var qrImage = document.getElementById('qr-image');
        if (qrImage) { qrImage.src = ''; qrImage.style.display = 'none'; }
        var qrLoading = document.getElementById('qr-loading');
        if (qrLoading) qrLoading.style.display = 'flex';
        var btn = $('#btn-generate-qr');
        if (btn) btn.disabled = false;

        // Reset confetti & tip celebration
        var confettiCanvas = document.getElementById('confetti-canvas');
        if (confettiCanvas) {
            var cctx = confettiCanvas.getContext('2d');
            cctx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
        }
        var tipBadge = document.getElementById('tip-badge');
        if (tipBadge) { tipBadge.style.display = 'none'; tipBadge.style.animation = ''; }
        var tipGlow = document.getElementById('tip-glow');
        if (tipGlow) tipGlow.style.display = 'none';

        switchState('amount');
    }

    // --- Verify (kept for gated onboarding) ---
    function showVerify() {
        switchState('verify');
    }

    function verifyUser() {
        var birthdate = $('#verify-birthdate').value;
        if (!birthdate) {
            alert('Voer de geboortedatum in');
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
            body: JSON.stringify({ user_id: null, birthdate: birthdate })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                show('#verify-success');
                hide('#verify-error');
                setTimeout(function() { resetAll(); }, 1200);
            } else {
                hide('#verify-success');
                show('#verify-error');
                $('#verify-error-msg').textContent = 'Verificatie mislukt. Probeer het opnieuw.';
                if (btn) btn.disabled = false;
            }
        })
        .catch(function(e) {
            alert('Er is een fout opgetreden');
            if (btn) btn.disabled = false;
        });
    }

    // --- Setup navigation ---
    function setupNav() {
        var genBtn = $('#btn-generate-qr');
        if (genBtn) genBtn.addEventListener('click', generateQR);

        var backBtn = $('#btn-back-amount');
        if (backBtn) backBtn.addEventListener('click', function() {
            stopPolling();
            stopCountdown();
            resetAll();
        });

        var nextBtn = $('#btn-next-guest');
        if (nextBtn) nextBtn.addEventListener('click', resetAll);

        var retryBtn = $('#btn-retry');
        if (retryBtn) retryBtn.addEventListener('click', resetAll);

        var verifyBtn = $('#btn-verify');
        if (verifyBtn) verifyBtn.addEventListener('click', verifyUser);

        var backVerify = $('#btn-back-scan-verify');
        if (backVerify) backVerify.addEventListener('click', resetAll);
    }

    // --- Init ---
    function init() {
        setupQuickAmounts();
        setupManualInputs();
        setupNav();
        updateAmountUI();
        switchState('amount');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
