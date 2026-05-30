<?php
declare(strict_types=1);
/**
 * REGULR.vip - Gast Wallet & Opwaarderen
 * Gast: Wallet bekijken en opwaarderen via Mollie
 */

// Set body class before header include
$bodyClass = 'guest-page wallet-page';

// Session and auth are already handled by index.php router.
// Additional role check for safety.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
    redirect(getGuestLoginUrl());
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;

// Account status check for gated onboarding
$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$accountStatus = $userModel->getAccountStatus((int) $user['user_id']);
$tenantModel = new Tenant($db);
$tenantData = $tenantModel->findById((int) ($user['tenant_id'] ?? 0));
$verificationRequired = (bool) ($tenantData['verification_required'] ?? true);
$isUnverified = ($accountStatus !== 'active' && $verificationRequired);
$pointsEnabled = (bool) ($tenantData['points_enabled'] ?? true);

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
                <?php if ($pointsEnabled): ?>
                <div class="wallet-balance-card__points">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    <span id="wallet-points">0</span> punten
                </div>
                <?php endif; ?>
                <div class="wallet-balance-card__tier">
                    <span class="tier-badge" id="wallet-tier">-</span>
                </div>
            </div>
        </div>
        <?php if ($tenant): ?>
        <div class="wallet-balance-card__tenant"><?= htmlspecialchars($tenant['name']) ?></div>
        <?php endif; ?>
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
        <a href="<?= BASE_URL ?>/pay" class="btn btn-outline">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Betaal
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

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script src="<?= BASE_URL ?>/public/js/wallet.js?v=<?= filemtime(PUBLIC_PATH . 'js/wallet.js') ?>"></script>

<?php require __DIR__ . '/../shared/footer.php'; ?>
