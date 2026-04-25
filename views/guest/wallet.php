<?php
declare(strict_types=1);
/**
 * STAMGAST - Gast Wallet & Opwaarderen
 * Gast: Wallet bekijken en opwaarderen via Mollie
 */

// Set body class before header include
$bodyClass = 'guest-page wallet-page';

// Session and auth are already handled by index.php router.
// Additional role check for safety.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
    redirect('/login');
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;

require __DIR__ . '/../shared/header.php';
?>

<main class="container wallet-page__main">
    <!-- Header -->
    <div class="page-header">
        <h1>Jouw Wallet</h1>
        <p class="text-muted">Beheer je saldo en opwaarderen</p>
    </div>

    <!-- Wallet Balance Card -->
    <div class="wallet-balance-card" id="wallet-card">
        <div class="wallet-balance-card__glow"></div>
        <div class="wallet-balance-card__content">
            <div class="wallet-balance-card__label">Saldo</div>
            <div class="wallet-balance-card__amount" id="wallet-balance">
                €0,00
            </div>
            <div class="wallet-balance-card__meta">
                <div class="wallet-balance-card__points">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    <span id="wallet-points">0</span> punten
                </div>
                <div class="wallet-balance-card__tier">
                    <span class="tier-badge" id="wallet-tier">-</span>
                </div>
            </div>
        </div>
        <?php if ($tenant): ?>
        <div class="wallet-balance-card__tenant"><?= htmlspecialchars($tenant['name']) ?></div>
        <?php endif; ?>
    </div>

    <!-- Deposit Section -->
    <div class="info-card glass-card">
        <h3>Opwaarderen</h3>

        <div class="deposit-options" id="deposit-options">
            <button class="btn btn-deposit-option" data-amount="500">€5</button>
            <button class="btn btn-deposit-option" data-amount="1000">€10</button>
            <button class="btn btn-deposit-option" data-amount="2500">€25</button>
            <button class="btn btn-deposit-option" data-amount="5000">€50</button>
            <button class="btn btn-deposit-option" data-amount="10000">€100</button>
        </div>

        <div class="custom-deposit">
            <label for="custom-amount">Of eigen bedrag:</label>
            <div class="custom-deposit__input-row">
                <span class="custom-deposit__prefix">€</span>
                <input type="text"
                       id="custom-amount"
                       class="form-input custom-deposit__field"
                       placeholder="5 - 500"
                       inputmode="decimal">
            </div>
            <button class="btn btn-primary" id="custom-deposit-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Opwaarderen
            </button>
        </div>

        <div class="security-note">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <span>Minimum €5, maximum €500. Betaling via Mollie.</span>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="info-card glass-card">
        <h3>Transactiegeschiedenis</h3>
        <div class="transaction-list" id="transaction-list">
            <div class="empty-state">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <p>Nog geen transacties</p>
                <p class="text-muted">Opwaarderen om te beginnen</p>
            </div>
        </div>

        <div class="pagination" id="history-pagination" style="display: none;">
            <button class="btn btn-secondary btn-sm" id="prev-page" disabled>
                &larr; Vorige
            </button>
            <span class="page-info" id="page-info">1</span>
            <button class="btn btn-secondary btn-sm" id="next-page">
                Volgende &rarr;
            </button>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="<?= BASE_URL ?>/qr" class="btn btn-outline">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
                <rect x="14" y="14" width="3" height="3"/>
                <line x1="21" y1="14" x2="21" y2="14.01"/>
                <line x1="21" y1="21" x2="21" y2="21.01"/>
                <line x1="17" y1="21" x2="17" y2="21.01"/>
                <line x1="21" y1="17" x2="21" y2="17.01"/>
            </svg>
            QR Code
        </a>
        <a href="<?= BASE_URL ?>/dashboard" class="btn btn-outline">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
            </svg>
            Dashboard
        </a>
    </div>
</main>

<!-- Alerts Container -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/wallet.js"></script>

<?php require __DIR__ . '/../shared/footer.php'; ?>
