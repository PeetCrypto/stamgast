<?php
declare(strict_types=1);

/**
 * REGULR.vip - Gast Scan & Betaal
 * Gast scant QR code van de barman, ziet bedragen, en bevestigt betaling
 */

$bodyClass = 'guest-page scan-page';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
    redirect(getGuestLoginUrl());
}

require __DIR__ . '/../shared/header.php';
?>

<main class="container" style="padding:var(--space-lg);max-width:500px;margin:0 auto;">

    <!-- ============ STATE: SCANNER ============ -->
    <div id="state-scanner">
        <div class="page-header" style="text-align:center;">
            <h1>Scan QR Code</h1>
             <p class="text-muted">Scan de QR code op het scherm van de barman. Houd de QR code binnen het vierkante vak.</p>
        </div>

        <!-- Camera viewport - verhoogd naar 280px voor betere scanning -->
        <div class="glass-card" style="padding:0;overflow:hidden;margin-bottom:1rem;position:relative;">
            <div id="qr-reader" style="width:280px;height:280px;margin:0 auto;background:#000;"></div>
            <!-- Debug overlay - zichtbaar op het scherm -->
            <div id="scan-debug" style="position:absolute;top:0;left:0;right:0;padding:4px 8px;background:rgba(0,0,0,0.7);color:#0f0;font-size:11px;font-family:monospace;z-index:10;display:none;"></div>
            <!-- Camera error placeholder -->
            <div id="camera-error" style="display:none;text-align:center;padding:3rem 1rem;">
                <p style="font-size:48px;margin-bottom:0.5rem;">&#128247;</p>
                <p style="color:var(--text-secondary);font-size:14px;">Camera kon niet starten</p>
                <p id="camera-error-msg" style="color:var(--error);font-size:13px;margin-top:0.5rem;"></p>
                <button class="btn btn-primary btn-sm" id="btn-retry-camera" style="margin-top:1rem;">Probeer opnieuw</button>
            </div>
        </div>

        <!-- Back to dashboard -->
        <div style="text-align:center;margin-top:1rem;">
            <a href="<?= BASE_URL ?>/dashboard" class="btn btn-ghost btn-sm">&larr; Terug naar dashboard</a>
        </div>
    </div>

    <!-- ============ STATE: CONFIRM ============ -->
    <div id="state-confirm" style="display:none;">
        <div class="page-header" style="text-align:center;">
            <h1>Bevestig Betaling</h1>
            <p id="confirm-tenant-name" style="color:var(--text-muted);font-size:14px;margin-top:0.25rem;"></p>
        </div>

        <!-- Amount breakdown -->
        <div class="glass-card" style="padding:var(--space-lg);margin-bottom:1rem;">
            <div id="confirm-amounts">
                <!-- Filled by JS -->
            </div>
        </div>

        <!-- Tip section -->
        <div id="tip-section" class="glass-card" style="padding:var(--space-md);margin-bottom:1rem;display:none;">
            <div style="text-align:center;margin-bottom:0.75rem;">
                <span style="font-size:16px;font-weight:600;color:var(--accent-primary);">Fooi geven? 💸</span>
            </div>
            <div id="tip-buttons" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                <!-- Filled by JS -->
            </div>
            <!-- Custom tip input (shown when "Anders" is tapped) -->
            <div id="tip-custom" style="display:none;margin-top:0.75rem;">
                <div style="display:flex;gap:6px;align-items:center;justify-content:center;">
                    <span style="color:var(--text-secondary);">€</span>
                    <input type="number" id="tip-custom-input" class="form-input" placeholder="0.00" min="0.50" max="100" step="0.01" style="max-width:100px;text-align:center;font-size:18px;font-weight:600;">
                    <button class="btn btn-primary btn-sm" id="tip-custom-confirm">OK</button>
                </div>
            </div>
        </div>

        <!-- Balance info -->
        <div class="glass-card" style="padding:1rem;margin-bottom:1.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="color:var(--text-muted);font-size:14px;">Jouw saldo</span>
                <span id="confirm-balance" style="font-weight:600;"></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.5rem;">
                <span style="color:var(--text-muted);font-size:14px;">Na betaling</span>
                <span id="confirm-after" style="font-weight:600;"></span>
            </div>
        </div>

        <!-- Insufficient balance warning -->
        <div id="confirm-insufficient" style="display:none;padding:1rem;border-radius:8px;background:rgba(244,67,54,0.1);border:1px solid rgba(244,67,54,0.3);margin-bottom:1rem;">
            <p style="color:#F44336;font-size:14px;margin:0;font-weight:600;">Onvoldoende saldo</p>
            <p id="confirm-shortage" style="color:var(--text-secondary);font-size:13px;margin:0.25rem 0 0;"></p>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:0.75rem;">
            <button class="btn btn-ghost" id="btn-cancel" style="flex:1;font-size:16px;padding:0.75rem;">Annuleer</button>
            <button class="btn btn-primary" id="btn-confirm-pay" style="flex:2;font-size:16px;padding:0.75rem;" disabled>
                Betaal
            </button>
        </div>
    </div>

    <!-- ============ STATE: PROCESSING ============ -->
    <div id="state-processing" style="display:none;text-align:center;padding:3rem 1rem;">
        <div class="spinner" style="margin:0 auto 1rem;"></div>
        <h2>Betaling verwerken...</h2>
        <p class="text-muted">Een moment geduld</p>
    </div>

    <!-- ============ STATE: SUCCESS ============ -->
    <div id="state-success" style="display:none;text-align:center;padding:3rem 1rem;">
        <div style="font-size:80px;line-height:1;margin-bottom:1rem;">&#10003;</div>
        <h2 style="color:#4CAF50;margin-bottom:0.5rem;">Betaling geslaagd!</h2>
        <p id="success-total" style="font-size:24px;font-weight:700;color:var(--accent-primary);margin-bottom:0.25rem;"></p>
        <p id="success-points" style="color:var(--text-muted);font-size:14px;margin-bottom:2rem;"></p>
        <div style="display:flex;gap:0.75rem;justify-content:center;">
            <button class="btn btn-ghost" id="btn-scan-again" style="flex:1;">Nog een betaling</button>
            <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary" style="flex:1;">Dashboard</a>
        </div>
    </div>

    <!-- ============ STATE: FAILED ============ -->
    <div id="state-failed" style="display:none;text-align:center;padding:3rem 1rem;">
        <div style="font-size:80px;line-height:1;margin-bottom:1rem;">&#10060;</div>
        <h2 style="color:#F44336;margin-bottom:0.5rem;">Betaling mislukt</h2>
        <p id="fail-reason" style="color:var(--text-secondary);margin-bottom:2rem;"></p>
        <a href="<?= BASE_URL ?>/pay" class="btn btn-primary">Opnieuw proberen</a>
    </div>

</main>

<!-- Alerts Container -->
<div class="alerts-container"></div>

<!-- QR Scanner library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
(function() {
    'use strict';

    // --- State ---
    var sessionToken = null;
    var paymentData = null;
    var qrScanner = null;
    var scanAttempts = 0;
    var selectedTipCents = 0;

    // --- Helpers ---
    function $(sel) { return document.querySelector(sel); }
    function fmtCents(c) { return '\u20AC ' + (c / 100).toFixed(2).replace('.', ','); }

    function show(el) { if (typeof el === 'string') el = $(el); if (el) el.style.display = ''; }
    function hide(el) { if (typeof el === 'string') el = $(el); if (el) el.style.display = 'none'; }

    function switchState(state) {
        hide('#state-scanner');
        hide('#state-confirm');
        hide('#state-processing');
        hide('#state-success');
        hide('#state-failed');
        show('#state-' + state);
    }

    function getCSRF() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function alert(msg) {
        var div = document.createElement('div');
        div.className = 'alert alert-error';
        div.textContent = msg;
        document.querySelector('.alerts-container').appendChild(div);
        setTimeout(function() { div.remove(); }, 4000);
    }

    // --- Debug overlay (zichtbaar op telefoon, geen F12 nodig) ---
    function debugStatus(msg) {
        var el = document.getElementById('scan-debug');
        if (el) {
            el.style.display = 'block';
            el.textContent = msg;
        }
    }

    // --- Scanner ---
    function startScanner() {
        if (qrScanner) return;

        // Stap 1: Check of library geladen is
        if (typeof Html5Qrcode === 'undefined') {
            debugStatus('FOUT: html5-qrcode library niet geladen!');
            showCameraError({ message: 'Scanner library kon niet laden. Herlaad de pagina.' });
            return;
        }

        debugStatus('Library geladen. Camera starten...');

        // Hide error, show reader
        var errorEl = document.getElementById('camera-error');
        var readerEl = document.getElementById('qr-reader');
        if (errorEl) errorEl.style.display = 'none';
        if (readerEl) readerEl.style.display = 'block';

        try {
            qrScanner = new Html5Qrcode('qr-reader');

            var config = {
                fps: 10,
                qrbox: function(viewfinderWidth, viewfinderHeight) {
                    var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                    var size = Math.floor(minEdge * 0.8);
                    return { width: size, height: size };
                },
                disableFlip: false
            };

            debugStatus('Camera toestemming vragen...');

            qrScanner.start(
                { facingMode: 'environment' },
                config,
                onQRDetected,
                function(error) {
                    // Dit wordt AANGEROEPEN bij elke scan poging die faalt
                    // Dit is normaal - de scanner probeert elke frame te decoderen
                    scanAttempts++;
                    if (scanAttempts % 30 === 0) {
                        // Toon elke 30 pogingen (~3 sec) op scherm
                        debugStatus('Scannen... (' + scanAttempts + ' pogingen)');
                    }
                }
            ).then(function() {
                debugStatus('Camera actief! Houd QR voor de camera');
            }).catch(function(err) {
                debugStatus('Camera kon niet worden gestart');
                qrScanner = null;
                showCameraError(err);
            });
        } catch (e) {
            debugStatus('Initialisatie mislukt');
            qrScanner = null;
            showCameraError(e);
        }
    }

    function showCameraError(err) {
        var readerEl = document.getElementById('qr-reader');
        var errorEl = document.getElementById('camera-error');
        var msgEl = document.getElementById('camera-error-msg');
        if (readerEl) readerEl.style.display = 'none';
        if (errorEl) errorEl.style.display = 'block';
        if (msgEl) {
            var msg = (err && err.message) ? err.message : String(err);
            if (msg.indexOf('NotAllowed') !== -1 || msg.indexOf('Permission') !== -1) {
                msgEl.textContent = 'Cameratoegang geweigerd. Geef toestemming in je browserinstellingen.';
            } else if (msg.indexOf('NotFound') !== -1) {
                msgEl.textContent = 'Geen camera gevonden op dit apparaat.';
            } else {
                msgEl.textContent = 'Camera kon niet starten. Probeer het opnieuw.';
            }
        }
    }

    function stopScanner() {
        if (!qrScanner) return;

        // 1. Stop direct de onderliggende MediaStreamTrack(s) op OS-niveau.
        //    Dit is synchroner dan qrScanner.stop() (een Promise) en voorkomt
        //    dat de camera geblokkeerd blijft als de pagina verdwijnt vóór de
        //    Promise resolveert (bekend probleem op iOS Safari / PWA).
        try {
            var videoEl = document.getElementById('qr-reader');
            if (videoEl && videoEl.srcObject) {
                var tracks = videoEl.srcObject.getTracks ? videoEl.srcObject.getTracks() : [];
                for (var i = 0; i < tracks.length; i++) {
                    try { tracks[i].stop(); } catch (e) {}
                }
                videoEl.srcObject = null;
            }
        } catch (e) {}

        // 2. Roep ook de library stop() aan voor interne cleanup.
        var scanner = qrScanner;
        qrScanner = null; // direct null'en om re-entrancy te voorkomen
        try {
            scanner.stop().then(function() {
                try { scanner.clear(); } catch (e) {}
            }).catch(function() { /* track al handmatig gestopt */ });
        } catch (e) { /* negeer */ }
    }

    function onQRDetected(decodedText) {
        // QR CODE GEVONDEN! Laat zien wat er gevonden is
        debugStatus('QR GEVONDEN: ' + (decodedText ? decodedText.substring(0, 40) : '(leeg)'));

        // Only process POS QR codes (start with "POS:")
        if (!decodedText.startsWith('POS:')) {
            alert('Dit is geen betalings-QR. Verwacht "POS:" aan het begin.');
            return;
        }
        stopScanner();
        processScan(decodedText);
    }

    // --- Scan processing ---
    function processScan(qrPayload) {
        switchState('processing');

        fetch((window.__BASE_URL || '') + '/api/guest/scan_payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ qr_payload: qrPayload })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                paymentData = data.data;
                sessionToken = paymentData.session_token;
                showConfirm();
            } else {
                showFailed(data.error || 'QR scan mislukt');
            }
        })
        .catch(function(e) {
            showFailed('Fout: ' + e.message);
        });
    }

    // --- Confirm UI ---
    function showConfirm() {
        var d = paymentData;
        selectedTipCents = 0;

        // Show tenant name so the guest knows which establishment they're paying
        var tenantNameEl = $('#confirm-tenant-name');
        if (tenantNameEl) {
            if (d.tenant_name) {
                tenantNameEl.textContent = 'Betaal aan ' + d.tenant_name;
                tenantNameEl.style.display = '';
            } else {
                tenantNameEl.style.display = 'none';
            }
        }

        var html = '';

        // Alcohol line
        if (d.amount_alc_cents > 0) {
            html += '<div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;">';
            html += '<span>\uD83C\uDF77 Alcohol</span>';
            html += '<span>' + fmtCents(d.amount_alc_cents) + '</span>';
            html += '</div>';
        }

        // Food line
        if (d.amount_food_cents > 0) {
            html += '<div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;">';
            html += '<span>\uD83E\uDD64 Non-Alcohol</span>';
            html += '<span>' + fmtCents(d.amount_food_cents) + '</span>';
            html += '</div>';
        }

        // Discounts
        if (d.discount_alc_cents > 0 || d.discount_food_cents > 0) {
            var totalDiscount = d.discount_alc_cents + d.discount_food_cents;
            html += '<div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;color:#4CAF50;">';
            html += '<span>\uD83D\uDCB0 Korting' + (d.tier_name ? ' (' + d.tier_name + ')' : '') + '</span>';
            html += '<span>-' + fmtCents(totalDiscount) + '</span>';
            html += '</div>';
        }

        // Separator
        html += '<div style="border-top:1px solid var(--border);margin:0.75rem 0;"></div>';

        // Total (without tip)
        html += '<div style="display:flex;justify-content:space-between;font-size:20px;font-weight:700;">';
        html += '<span>Totaal</span>';
        html += '<span style="color:var(--accent-primary);" id="confirm-total-value">' + fmtCents(d.final_total_cents) + '</span>';
        html += '</div>';

        // Tip line (hidden initially, shown when tip selected)
        html += '<div id="confirm-tip-line" style="display:none;margin-top:0.5rem;">';
        html += '<div style="display:flex;justify-content:space-between;font-size:14px;">';
        html += '<span style="color:var(--text-secondary);">Fooi</span>';
        html += '<span id="confirm-tip-value" style="color:var(--accent-primary);"></span>';
        html += '</div>';
        html += '</div>';

        // Grand total line (hidden initially)
        html += '<div id="confirm-grand-total-line" style="display:none;border-top:1px solid var(--border);margin-top:0.5rem;padding-top:0.5rem;">';
        html += '<div style="display:flex;justify-content:space-between;font-size:20px;font-weight:700;">';
        html += '<span>Totaal incl. fooi</span>';
        html += '<span style="color:var(--accent-primary);" id="confirm-grand-total-value"></span>';
        html += '</div>';
        html += '</div>';

        $('#confirm-amounts').innerHTML = html;

        // Render tip buttons
        renderTipButtons(d.tip_options_cents || []);

        // Balance
        updateBalanceDisplay();

        switchState('confirm');
    }

    // --- Tip buttons ---
    function renderTipButtons(tipOptions) {
        var container = $('#tip-buttons');
        var section = $('#tip-section');
        if (!container || !section) return;

        // Hide tip section if no options
        if (!tipOptions || tipOptions.length === 0) {
            section.style.display = 'none';
            return;
        }
        section.style.display = 'block';

        var html = '';

        // Preset tip buttons
        for (var i = 0; i < tipOptions.length; i++) {
            var cents = tipOptions[i];
            html += '<button class="tip-btn" data-tip="' + cents + '" style="' +
                'flex:1;min-width:70px;max-width:120px;padding:12px 8px;' +
                'border:2px solid var(--glass-border);border-radius:12px;' +
                'background:rgba(255,255,255,0.05);color:var(--text-primary);' +
                'font-size:18px;font-weight:700;cursor:pointer;' +
                'transition:all 0.2s ease;text-align:center;">' +
                fmtCents(cents) +
                '</button>';
        }

        // "Anders" button
        html += '<button class="tip-btn tip-btn-custom" data-tip="custom" style="' +
            'flex:1;min-width:70px;max-width:120px;padding:12px 8px;' +
            'border:2px solid var(--glass-border);border-radius:12px;' +
            'background:rgba(255,255,255,0.05);color:var(--text-secondary);' +
            'font-size:14px;font-weight:500;cursor:pointer;' +
            'transition:all 0.2s ease;text-align:center;">' +
            'Anders' +
            '</button>';

        container.innerHTML = html;

        // Wire up tip buttons
        var buttons = container.querySelectorAll('.tip-btn');
        for (var j = 0; j < buttons.length; j++) {
            buttons[j].addEventListener('click', function() {
                var tipVal = this.getAttribute('data-tip');
                if (tipVal === 'custom') {
                    showCustomTipInput();
                } else {
                    selectTip(parseInt(tipVal, 10));
                }
            });
        }
    }

    function showCustomTipInput() {
        var customDiv = $('#tip-custom');
        if (customDiv) customDiv.style.display = 'block';
        var input = $('#tip-custom-input');
        if (input) input.focus();
        // Highlight the "Anders" button
        highlightTipButton('custom');
    }

    function confirmCustomTip() {
        var input = $('#tip-custom-input');
        if (!input) return;
        var val = parseFloat(input.value);
        if (isNaN(val) || val < 0.50) {
            input.style.borderColor = '#F44336';
            return;
        }
        if (val > 100) {
            input.style.borderColor = '#F44336';
            return;
        }
        input.style.borderColor = '';
        var cents = Math.round(val * 100);
        selectTip(cents);
    }

    function selectTip(cents) {
        selectedTipCents = cents;

        // Hide custom input
        var customDiv = $('#tip-custom');
        if (customDiv) customDiv.style.display = 'none';

        // Update tip line and grand total in the amounts card
        var tipLine = $('#confirm-tip-line');
        var tipValue = $('#confirm-tip-value');
        var grandTotalLine = $('#confirm-grand-total-line');
        var grandTotalValue = $('#confirm-grand-total-value');

        if (cents > 0) {
            if (tipLine) tipLine.style.display = 'block';
            if (tipValue) tipValue.textContent = fmtCents(cents);
            if (grandTotalLine) grandTotalLine.style.display = 'block';
            if (grandTotalValue) grandTotalValue.textContent = fmtCents(paymentData.final_total_cents + cents);
        } else {
            if (tipLine) tipLine.style.display = 'none';
            if (grandTotalLine) grandTotalLine.style.display = 'none';
        }

        // Highlight selected button
        highlightTipButton(cents);

        // Update balance display
        updateBalanceDisplay();
    }

    function highlightTipButton(value) {
        var buttons = document.querySelectorAll('.tip-btn');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var btnVal = btn.getAttribute('data-tip');
            var isSelected = (btnVal === String(value)) || (value === 'custom' && btnVal === 'custom');
            if (isSelected) {
                btn.style.borderColor = 'var(--accent-primary)';
                btn.style.background = 'rgba(255,193,7,0.15)';
                btn.style.color = 'var(--accent-primary)';
            } else {
                btn.style.borderColor = 'var(--glass-border)';
                btn.style.background = 'rgba(255,255,255,0.05)';
                // Restore original colors based on type
                if (btn.classList.contains('tip-btn-custom')) {
                    btn.style.color = 'var(--text-secondary)';
                } else {
                    btn.style.color = 'var(--text-primary)';
                }
            }
        }
    }

    function updateBalanceDisplay() {
        if (!paymentData) return;
        var d = paymentData;
        var balance = d.balance_cents;
        var grandTotal = d.final_total_cents + selectedTipCents;
        var afterBalance = balance - grandTotal;
        var sufficient = balance >= grandTotal;

        $('#confirm-balance').textContent = fmtCents(balance);
        $('#confirm-balance').style.color = 'var(--text-primary)';

        $('#confirm-after').textContent = fmtCents(Math.max(0, afterBalance));
        $('#confirm-after').style.color = afterBalance >= 0 ? '#4CAF50' : '#F44336';

        // Insufficient balance warning
        if (!sufficient) {
            var shortage = grandTotal - balance;
            show('#confirm-insufficient');
            $('#confirm-shortage').textContent = 'Je hebt nog \u20AC ' + (shortage / 100).toFixed(2).replace('.', ',') + ' te weinig. Laad eerst je wallet op.';
            $('#btn-confirm-pay').disabled = true;
        } else {
            hide('#confirm-insufficient');
            $('#btn-confirm-pay').disabled = false;
        }
    }

    // --- Confirm payment ---
    function confirmPayment() {
        if (!sessionToken) return;
        var btn = $('#btn-confirm-pay');
        if (btn) btn.disabled = true;

        switchState('processing');

        fetch((window.__BASE_URL || '') + '/api/guest/confirm_payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ session_token: sessionToken, tip_cents: selectedTipCents })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showSuccess(data.data);
            } else {
                showFailed(data.error || 'Betaling mislukt');
            }
        })
        .catch(function(e) {
            showFailed('Fout: ' + e.message);
        });
    }

    // --- Cancel payment ---
    function cancelPayment() {
        if (!sessionToken) {
            switchState('scanner');
            startScanner();
            return;
        }

        fetch((window.__BASE_URL || '') + '/api/guest/cancel_payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRF()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ session_token: sessionToken })
        }).catch(function() {});

        // Go back to scanner regardless of API result
        sessionToken = null;
        paymentData = null;
        switchState('scanner');
        startScanner();
    }

    // --- Success / Failed ---
    function showSuccess(data) {
        var totalWithTip = (data.final_total || 0);
        $('#success-total').textContent = fmtCents(totalWithTip);
        var parts = [];
        if (selectedTipCents > 0) parts.push('Fooi: ' + fmtCents(selectedTipCents));
        if (data.points_earned > 0) parts.push('+' + data.points_earned + ' punten verdiend');
        $('#success-points').textContent = parts.join(' • ');
        switchState('success');

        // Reset state zodat een nieuwe betaling direct mogelijk is
        sessionToken = null;
        paymentData = null;
        selectedTipCents = 0;
        stopScanner();
    }

    function showFailed(reason) {
        $('#fail-reason').textContent = reason || 'Onbekende fout';
        switchState('failed');
    }

    // --- Button wiring ---
    function setupButtons() {
        var payBtn = $('#btn-confirm-pay');
        if (payBtn) payBtn.addEventListener('click', confirmPayment);

        var cancelBtn = $('#btn-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelPayment);

        // Scan again after success
        var scanAgainBtn = $('#btn-scan-again');
        if (scanAgainBtn) scanAgainBtn.addEventListener('click', function() {
            switchState('scanner');
            startScanner();
        });

        // Custom tip confirm button
        var customConfirmBtn = $('#tip-custom-confirm');
        if (customConfirmBtn) customConfirmBtn.addEventListener('click', confirmCustomTip);

        // Custom tip Enter key
        var customInput = $('#tip-custom-input');
        if (customInput) customInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') confirmCustomTip();
        });
    }

    // --- Init ---
    function init() {
        setupButtons();
        // Retry camera button
        var retryBtn = document.getElementById('btn-retry-camera');
        if (retryBtn) retryBtn.addEventListener('click', function() { startScanner(); });
        switchState('scanner');
        startScanner();

        // --- Camera cleanup bij navigatie / app-achtergrond ---
        // Zonder deze handlers blijft de getUserMedia track actief op OS-niveau
        // als de gast weg navigeert (bijv. naar /wallet om een pakket te kopen).
        // Op mobiel (vooral iOS) blokkeert dit de camera bij de volgende /pay
        // visit — de gast moet dan de app herstarten.
        function cleanupCamera() { stopScanner(); }

        window.addEventListener('pagehide', cleanupCamera);
        window.addEventListener('beforeunload', cleanupCamera);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                stopScanner();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php require __DIR__ . '/../shared/footer.php'; ?>
