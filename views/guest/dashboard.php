<?php
declare(strict_types=1);
/**
 * Guest Dashboard
 * STAMGAST Loyalty Platform
 */

require_once __DIR__ . '/../../models/Wallet.php';

$db = Database::getInstance()->getConnection();
$userId = currentUserId();
$tenantId = currentTenantId();
$firstName = $_SESSION['first_name'] ?? 'Gast';

// Get wallet balance
$walletModel = new Wallet($db);
$wallet = $walletModel->findByUserId($userId);
$balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;
$pointsCents = $wallet ? (int) $wallet['points_cents'] : 0;
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Hoi, <?= sanitize($firstName) ?>!</h1>

    <!-- Wallet Card -->
    <div class="glass-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); border: 2px solid var(--accent-primary); text-align: center;">
        <p class="text-secondary text-sm">Je saldo</p>
        <p style="font-size: 48px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($balanceCents / 100, 2, ',', '.') ?></p>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);"><?= number_format($pointsCents / 100, 0) ?> punten</p>
        <a href="<?= BASE_URL ?>/wallet" class="btn btn-primary" style="margin-top: var(--space-md);">Opwaarderen</a>
    </div>

    <!-- Quick Actions -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <a href="<?= BASE_URL ?>/qr" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#9203;</p>
            <p class="text-sm">QR Code</p>
        </a>
        <a href="<?= BASE_URL ?>/wallet" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128176;</p>
            <p class="text-sm">Wallet</p>
        </a>
        <a href="<?= BASE_URL ?>/inbox" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px; margin-bottom: var(--space-xs);">&#128233;</p>
            <p class="text-sm">Inbox</p>
        </a>
    </div>

    <a href="<?= BASE_URL ?>/logout" class="btn btn-secondary">Uitloggen</a>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
