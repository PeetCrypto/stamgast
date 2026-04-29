<?php
declare(strict_types=1);
/**
 * Superadmin Dashboard - Platform Overview
 * STAMGAST Loyalty Platform
 */

require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$stats = $tenantModel->getPlatformStats();

// Platform fee totals
$feeService = new PlatformFeeService($db);
$feeTotals = $feeService->getPlatformTotals();

?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Platform Overzicht</h1>

    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Tenants</p>
            <p style="font-size: 32px; font-weight: 700; color: var(--accent-primary);"><?= (int) $stats['total_tenants'] ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Gebruikers</p>
            <p style="font-size: 32px; font-weight: 700; color: var(--accent-primary);"><?= (int) $stats['total_users'] ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Omzet (betalingen)</p>
            <p style="font-size: 32px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['total_revenue'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Stortingen</p>
            <p style="font-size: 32px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['total_deposits'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Platform Fee Vandaag</p>
            <p style="font-size: 32px; font-weight: 700; color: #4CAF50;">&euro; <?= number_format($feeTotals['today_fee'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Platform Fee Deze Maand</p>
            <p style="font-size: 32px; font-weight: 700; color: #4CAF50;">&euro; <?= number_format($feeTotals['month_fee'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Platform Fee All-Time</p>
            <p style="font-size: 32px; font-weight: 700; color: #4CAF50;">&euro; <?= number_format($feeTotals['all_fee'] / 100, 2, ',', '.') ?></p>
        </div>
    </div>

    <!-- Acties -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Beheer</h2>
        <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/superadmin/tenants" class="btn btn-primary">Beheer Tenants</a>
            <a href="<?= BASE_URL ?>/superadmin/fees" class="btn btn-primary" style="background:#4CAF50;">Platform Fees</a>
            <a href="<?= BASE_URL ?>/superadmin/invoices" class="btn btn-primary" style="background:#FF9800;">Facturen</a>
            <a href="<?= BASE_URL ?>/superadmin/settings" class="btn btn-secondary">Platform Instellingen</a>
        </div>
    </div>

    <div style="margin-top: var(--space-xl); text-align: center;">
        <a href="<?= BASE_URL ?>/logout" class="btn btn-secondary">Uitloggen</a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
