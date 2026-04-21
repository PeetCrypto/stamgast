<?php
/**
 * STAMGAST - Gast QR Code
 * Gast: Dynamische QR code voor betalingen
 */
require_once __DIR__ . '/../shared/header.php';

// Check authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guest') {
    header('Location: /login');
    exit;
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;
?>
<body class="guest-page qr-page">
    <main class="main-container">
        <!-- Header -->
        <div class="page-header">
            <h1>Jouw QR Code</h1>
            <p class="text-muted">Scan deze code bij de bar om te betalen</p>
        </div>

        <!-- QR Box -->
        <div class="qr-box glass-card">
            <div class="qr-timer">
                <span class="timer-label">Geldig over:</span>
                <span class="timer-value" id="qr-countdown">60s</span>
            </div>
            
            <div class="qr-container" id="qr-container">
                <div class="qr-placeholder">
                    <div class="spinner"></div>
                    <p>QR code laden...</p>
                </div>
            </div>
            
            <button class="btn btn-secondary btn-refresh" id="qr-refresh-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6"/>
                    <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                </svg>
                Vernieuwen
            </button>
        </div>

        <!-- Info Card -->
        <div class="info-card glass-card">
            <h3>Hoe werkt het?</h3>
            <ol class="steps-list">
                <li>Toon deze QR code aan de bartender</li>
                <li>De bartender scant de code</li>
                <li>Je ontvangt een notificatie met het bedrag</li>
                <li>Je saldo wordt automatisch verminderd</li>
            </ol>
            
            <div class="security-note">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
                <span>Je QR code is 60 seconden geldig en beveiligd met cryptografie</span>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="/wallet" class="btn btn-outline">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
                Naar Wallet
            </a>
            <a href="/dashboard" class="btn btn-outline">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                </svg>
                Dashboard
            </a>
        </div>
    </main>

    <!-- Alerts Container -->
    <div class="alerts-container"></div>

    <?php require_once __DIR__ . '/../shared/footer.php'; ?>
    
    <!-- QRCode.js library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script src="/public/js/app.js"></script>
    <script src="/public/js/qr.js"></script>
</body>
</html>