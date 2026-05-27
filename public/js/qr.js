/**
 * REGULR.vip - QR Generatie & Weergave
 * Gast: Dynamische QR code voor betalingen
 */
(function() {
    'use strict';

    let qrData = null;
    let refreshTimer = null;
    let countdownTimer = null;
    let qrInitialized = false; // Guard against double-init
    const QR_EXPIRY_SECONDS = 60;
    const QR_REFRESH_THRESHOLD = 55;

    // ============================================
    // QR CODE GENERATION
    // ============================================
    async function generateQR() {
        try {
            const response = await window.REGULR.api('/qr/generate');
            
            if (response.success) {
                qrData = response.data;
                renderQRCode(qrData.qr_data);
                startExpiryCountdown(qrData.expires_at);
                return qrData;
            }
            
            throw new Error(response.error || 'Failed to generate QR');
        } catch (error) {
            console.error('QR generation error:', error);
            window.REGULR.showError('Kon QR code niet genereren');
            return null;
        }
    }

    function renderQRCode(qrPayload) {
        const container = document.getElementById('qr-container');
        if (!container) return;

        container.innerHTML = '';

        // Option 1: qrcodejs library — constructor API (new QRCode(container, opts))
        if (typeof QRCode === 'function' && QRCode.CorrectLevel) {
            try {
                new QRCode(container, {
                    text: qrPayload,
                    width: 250,
                    height: 250,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch (err) {
                console.error('QRCode constructor error:', err);
                container.innerHTML = '';
                renderQRCanvas(qrPayload, container);
            }
        }
        // Option 2: qrcode npm package — QRCode.toCanvas() API
        else if (typeof QRCode !== 'undefined' && typeof QRCode.toCanvas === 'function') {
            const canvas = document.createElement('canvas');
            container.appendChild(canvas);
            QRCode.toCanvas(canvas, qrPayload, {
                width: 250,
                margin: 2,
                color: { dark: '#000000', light: '#ffffff' }
            }, function(error) {
                if (error) {
                    console.error('QR toCanvas error:', error);
                    container.innerHTML = '';
                    renderQRCanvas(qrPayload, container);
                }
            });
        }
        // Option 3: Fallback — show payload as text
        else {
            console.warn('No QR library loaded, using text fallback');
            renderQRCanvas(qrPayload, container);
        }

        // Add pulsing neon glow effect to the QR wrapper
        const qrBox = document.getElementById('qr-wrapper');
        if (qrBox) {
            qrBox.classList.add('qr-box--glow');
        }
    }

    function renderQRCanvas(payload, container) {
        // Fallback when no QR library is available — show payload as text
        container.innerHTML = `
            <div class="qr-fallback" style="padding:16px;text-align:center;">
                <p style="color:var(--text-secondary);margin-bottom:8px;">QR Data:</p>
                <code style="word-break:break-all;font-size:11px;color:var(--text-primary);">${payload}</code>
                <p style="color:var(--accent-primary);margin-top:12px;font-size:13px;">
                    ⚠️ QR library niet geladen
                </p>
            </div>
        `;
    }

    // ============================================
    // COUNTDOWN & AUTO-REFRESH
    // ============================================
    function startExpiryCountdown(expiresAt) {
        const countdownEl = document.getElementById('qr-countdown');
        if (!countdownEl) return;

        // Clear existing timers
        if (countdownTimer) clearInterval(countdownTimer);
        if (refreshTimer) clearTimeout(refreshTimer);

        countdownTimer = setInterval(() => {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expiresAt - now;

            if (remaining <= 0) {
                // QR expired — clear interval to prevent duplicate generateQR() calls
                clearInterval(countdownTimer);
                countdownTimer = null;
                countdownEl.textContent = 'Verlopen...';
                countdownEl.classList.add('expired');
                generateQR();
                return;
            }

            // Update display
            countdownEl.textContent = `${remaining}s`;
            
            // Visual warning when getting close to expiry
            if (remaining <= 10) {
                countdownEl.classList.add('warning');
            } else {
                countdownEl.classList.remove('warning');
            }

            // Auto-refresh when approaching expiry (only schedule once)
            if (remaining <= QR_REFRESH_THRESHOLD && !refreshTimer) {
                const delayMs = Math.max(0, (remaining - 5)) * 1000;
                refreshTimer = setTimeout(() => {
                    refreshTimer = null;   // Clear reference so next cycle can reschedule
                    generateQR();
                }, delayMs);
            }
        }, 1000);
    }

    // ============================================
    // MANUAL REFRESH
    // ============================================
    function setupManualRefresh() {
        const refreshBtn = document.getElementById('qr-refresh-btn');
        if (!refreshBtn) return;

        refreshBtn.addEventListener('click', async () => {
            const qrBox = document.getElementById('qr-wrapper');
            if (qrBox) {
                qrBox.classList.add('refreshing');
            }
            
            await generateQR();
            
            setTimeout(() => {
                if (qrBox) {
                    qrBox.classList.remove('refreshing');
                }
            }, 500);
        });
    }

    // ============================================
    // QR SCANNER (FOR BARTENDER)
    // ============================================
    let scannerActive = false;
    let videoStream = null;

    async function initScanner() {
        const video = document.getElementById('scanner-video');
        const startBtn = document.getElementById('start-scan-btn');
        
        if (!video || !startBtn) return;

        startBtn.addEventListener('click', async () => {
            try {
                // Request camera access
                videoStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' }
                });
                
                video.srcObject = videoStream;
                video.classList.add('scanning');
                scannerActive = true;
                
                // Start scanning loop
                scanFrame();
                
            } catch (error) {
                console.error('Scanner error:', error);
                window.REGULR.showError('Kon camera niet starten');
            }
        });
    }

    function scanFrame() {
        if (!scannerActive) return;

        const video = document.getElementById('scanner-video');
        const canvas = document.createElement('canvas');
        
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            
            // Note: For actual QR scanning, you'd use a library like jsQR
            // This is the placeholder for the scanning logic
        }

        if (scannerActive) {
            requestAnimationFrame(scanFrame);
        }
    }

    function stopScanner() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
        scannerActive = false;
        
        const video = document.getElementById('scanner-video');
        if (video) {
            video.srcObject = null;
            video.classList.remove('scanning');
        }
    }

    // ============================================
    // QR VALIDATION (FOR BARTENDER)
    // ============================================
    async function validateQR(qrPayload) {
        try {
            const response = await window.REGULR.api('/pos/scan', {
                method: 'POST',
                body: {
                    qr_payload: qrPayload
                }
            });

            if (response.success) {
                return response.data;
            }

            throw new Error(response.error || 'Invalid QR');
        } catch (error) {
            console.error('QR validation error:', error);
            return { valid: false, error: error.message };
        }
    }

    function displayScanResult(userData) {
        const resultContainer = document.getElementById('scan-result');
        if (!resultContainer) return;

        if (!userData.valid) {
            resultContainer.innerHTML = `
                <div class="scan-error">
                    <i class="icon-error"></i>
                    <p>${userData.error || 'Ongeldige QR code'}</p>
                </div>
            `;
            return;
        }

        // Build user info display
        const ageBadge = userData.age >= 18 
            ? '<span class="badge badge-success">18+</span>'
            : '<span class="badge badge-danger">18-</span>';

        resultContainer.innerHTML = `
            <div class="scan-success">
                <img src="${userData.user?.photo_url || '/public/icons/default-avatar.png'}" 
                     alt="Profiel" class="user-photo">
                <h3>${userData.user?.first_name} ${userData.user?.last_name}</h3>
                <div class="user-meta">
                    ${ageBadge}
                    ${userData.tier ? `<span class="tier-badge">${userData.tier.name}</span>` : ''}
                </div>
                <div class="wallet-preview">
                    <span>Saldo: ${window.REGULR.formatCurrency(userData.wallet?.balance_cents || 0)}</span>
                </div>
            </div>
        `;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    async function initQR() {
        // Prevent double initialization (app.js route handler + auto-init)
        if (qrInitialized) {
            console.log('QR already initialized, skipping');
            return;
        }
        qrInitialized = true;
        console.log('Initializing QR...');
        
        // Check if this is a scanner view (bartender)
        if (document.getElementById('scanner-video')) {
            initScanner();
            return;
        }
        
        // Guest QR display - generate new QR
        await generateQR();
        
        // Setup manual refresh
        setupManualRefresh();
        
        console.log('QR initialized');
    }

    // Export to global REGULR.vip namespace
    window.REGULR = window.REGULR || {};
    window.REGULR.qr = {
        init: initQR,
        generate: generateQR,
        validate: validateQR,
        displayResult: displayScanResult,
        stopScanner: stopScanner
    };

    // Also expose as global function so app.js can call it as fallback
    window.initQR = initQR;

    // NOTE: No auto-init here. Initialization is exclusively handled by
    // app.js via the route handler (window.REGULR.qr.init()).
    // This prevents the double-init race condition where qr.js auto-init
    // and app.js handleRoute() both fire generateQR() simultaneously.

})();
