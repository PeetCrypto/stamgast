<?php
/**
 * STAMGAST - Gast Wallet & Opwaarderen
 * Gast: Wallet bekijken en opwaarderen via Mollie
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
<body class="guest-page wallet-page">
    <main class="main-container">
        <!-- Wallet Card -->
        <div class="wallet-card glass-card">
            <div class="wallet-header">
                <h1>Jouw Wallet</h1>
                <?php if ($tenant): ?>
                <span class="tenant-name"><?= htmlspecialchars($tenant['name']) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="wallet-balance-display">
                <div class="balance-label">Saldo</div>
                <div class="balance-amount" id="wallet-balance">
                    €0,00
                </div>
            </div>
            
            <div class="wallet-points-display">
                <div class="points-label">Loyalteitspunten</div>
                <div class="points-amount" id="wallet-points">
                    0
                </div>
            </div>
            
            <div class="wallet-tier" id="wallet-tier">
                <span class="tier-badge">-</span>
            </div>
        </div>

        <!-- Deposit Section -->
        <section class="deposit-section">
            <h2>Opwaarderen</h2>
            
            <div class="deposit-options" id="deposit-options">
                <button class="btn btn-deposit-option" data-amount="500">
                    €5
                </button>
                <button class="btn btn-deposit-option" data-amount="1000">
                    €10
                </button>
                <button class="btn btn-deposit-option" data-amount="2500">
                    €25
                </button>
                <button class="btn btn-deposit-option" data-amount="5000">
                    €50
                </button>
                <button class="btn btn-deposit-option" data-amount="10000">
                    €100
                </button>
            </div>
            
            <div class="custom-deposit">
                <label for="custom-amount">Of eigen bedrag:</label>
                <div class="input-group">
                    <span class="input-prefix">€</span>
                    <input type="text" 
                           id="custom-amount" 
                           class="input-field" 
                           placeholder="5 - 500"
                           inputmode="decimal">
                </div>
                <button class="btn btn-primary" id="custom-deposit-btn">
                    Opwaarderen
                </button>
            </div>
        </section>

        <!-- Transaction History -->
        <section class="history-section">
            <h2>Transactiegeschiedenis</h2>
            <div class="transaction-list" id="transaction-list">
                <div class="empty-state">
                    <p>Nog geen transacties</p>
                    <p class="text-muted">Opwaarderen om te beginnen</p>
                </div>
            </div>
            
            <div class="pagination" id="history-pagination" style="display: none;">
                <button class="btn btn-secondary" id="prev-page" disabled>
                    &larr; Vorige
                </button>
                <span class="page-info" id="page-info">1</span>
                <button class="btn btn-secondary" id="next-page">
                    Volgende &rarr;
                </button>
            </div>
        </section>
    </main>

    <!-- Alerts Container -->
    <div class="alerts-container"></div>

    <?php require_once __DIR__ . '/../shared/footer.php'; ?>
    
    <script src="/public/js/app.js"></script>
    <script src="/public/js/wallet.js"></script>
</body>
</html>