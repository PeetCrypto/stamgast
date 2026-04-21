/**
 * STAMGAST - QR Generatie & Weergave
 * Gast: Dynamische QR code voor betalingen
 */
(function() {
    'use strict';

    let qrData = null;
    let refreshTimer = null;
    let countdownTimer = null;
    const QR_EXPIRY_SECONDS = 60;
    const QR_REFRESH_THRESHOLD = 55;

    // ============================================
    // QR CODE GENERATION
    // ============================================
    async function generateQR() {
        try {
            const response = await window.STAMGAST.api('/qr/generate');
            
            if (response.success) {
                qrData = response.data;
                renderQRCode(qrData.qr_data);
                startExpiryCountdown(qrData.expires_at);
                return qrData;
            }
            
            throw new Error(response.error || 'Failed to generate QR');
        } catch (error) {
            console.error('QR generation error:', error);
            window.STAMGAST.showError('Kon QR code niet genereren');
            return null;
        }
    }

    function renderQRCode(qrPayload) {
        const container = document.getElementById('qr-container');
        if (!container) return;

        // Use QRCode.js library (loaded from CDN or local)
        if (typeof QRCode !== 'undefined') {
            container.innerHTML = '';
            new QRCode(container, {
                text: qrPayload,
                width: 250,
                height: 250,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            // Fallback: display as data URL using a canvas-based approach
            renderQRCanvas(qrPayload, container);
        }

        // Add pulsing neon effect
        const qrBox = document.querySelector('.qr-box');
        if (qrBox) {
            qrBox.classList.add('qr-active');
        }
    }

    function renderQRCanvas(payload, container) {
        // Simple fallback - create canvas and use built-in QR generation
        const canvas = document.createElement('canvas');
        canvas.width = 250;
        canvas.height = 250;
        
        // Note: In production, you'd include a QR library like qrcode.js
        // For now, show the payload as text for testing
        container.innerHTML = `
            <div class="qr-fallback">
                <p>QR Payload:</p>
                <code>${payload.substring(0, 50)}...</code>
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
                // QR expired, generate new one
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

            // Auto-refresh when approaching expiry
            if (remaining <= QR_REFRESH_THRESHOLD && !refreshTimer) {
                refreshTimer = setTimeout(() => {
                    generateQR();
                }, (remaining - 5) * 1000);
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
            const qrBox = document.querySelector('.qr-box');
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
                window.STAMGAST.showError('Kon camera niet starten');
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
            const response = await window.STAMGAST.api('/pos/scan', {
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
                    <span>Saldo: ${window.STAMGAST.formatCurrency(userData.wallet?.balance_cents || 0)}</span>
                </div>
            </div>
        `;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    async function initQR() {
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

    // Export to global
    window.STAMGAST = window.STAMGAST || {};
    window.STAMGAST.qr = {
        init: initQR,
        generate: generateQR,
        validate: validateQR,
        displayResult: displayScanResult,
        stopScanner: stopScanner
    };

    // Auto-init if on QR page
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQR);
    } else if (window.location.pathname.includes('/qr') || 
               window.location.pathname.includes('/scan')) {
        initQR();
    }

})();
