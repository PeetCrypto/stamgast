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

        <!-- Manual fallback -->
        <div class="glass-card" style="padding:1rem;">
            <details>
                <summary style="color:var(--text-muted);font-size:13px;cursor:pointer;">Handmatige QR invoer</summary>
                <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                    <input type="text" id="manual-qr-input" class="form-input" placeholder="Plak QR code data..." style="font-size:13px;">
                    <button class="btn btn-primary btn-sm" id="manual-qr-btn" style="white-space:nowrap;">Scan</button>
                </div>
            </details>
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
        <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary">Terug naar dashboard</a>
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
                msgEl.textContent = 'Camera kon niet starten. Gebruik handmatige invoer hieronder.';
            }
        }
    }

    function stopScanner() {
        if (qrScanner) {
            try {
                qrScanner.stop().then(function() {
                    qrScanner.clear();
                    qrScanner = null;
                }).catch(function() { qrScanner = null; });
            } catch (e) { qrScanner = null; }
        }
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
            html += '<span>\uD83C\uDF5F Eten</span>';
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

        // Total
        html += '<div style="display:flex;justify-content:space-between;font-size:20px;font-weight:700;">';
        html += '<span>Totaal</span>';
        html += '<span style="color:var(--accent-primary);">' + fmtCents(d.final_total_cents) + '</span>';
        html += '</div>';

        $('#confirm-amounts').innerHTML = html;

        // Balance
        var balance = d.balance_cents;
        var afterBalance = balance - d.final_total_cents;
        var sufficient = d.sufficient_balance;

        $('#confirm-balance').textContent = fmtCents(balance);
        $('#confirm-balance').style.color = 'var(--text-primary)';

        $('#confirm-after').textContent = fmtCents(Math.max(0, afterBalance));
        $('#confirm-after').style.color = afterBalance >= 0 ? '#4CAF50' : '#F44336';

        // Insufficient balance warning
        if (!sufficient) {
            var shortage = d.final_total_cents - balance;
            show('#confirm-insufficient');
            $('#confirm-shortage').textContent = 'Je hebt nog \u20AC ' + (shortage / 100).toFixed(2).replace('.', ',') + ' te weinig. Laad eerst je wallet op.';
            $('#btn-confirm-pay').disabled = true;
        } else {
            hide('#confirm-insufficient');
            $('#btn-confirm-pay').disabled = false;
        }

        switchState('confirm');
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
            body: JSON.stringify({ session_token: sessionToken })
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
        $('#success-total').textContent = fmtCents(data.final_total || 0);
        $('#success-points').textContent = data.points_earned > 0 ? ('+' + data.points_earned + ' punten verdiend') : '';
        switchState('success');
    }

    function showFailed(reason) {
        $('#fail-reason').textContent = reason || 'Onbekende fout';
        switchState('failed');
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
                processScan(val);
                input.value = '';
            }
        });
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') btn.click();
        });
    }

    // --- Button wiring ---
    function setupButtons() {
        var payBtn = $('#btn-confirm-pay');
        if (payBtn) payBtn.addEventListener('click', confirmPayment);

        var cancelBtn = $('#btn-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelPayment);
    }

    // --- Init ---
    function init() {
        setupManualQR();
        setupButtons();
        // Retry camera button
        var retryBtn = document.getElementById('btn-retry-camera');
        if (retryBtn) retryBtn.addEventListener('click', function() { startScanner(); });
        switchState('scanner');
        startScanner();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php require __DIR__ . '/../shared/footer.php'; ?>
