<?php
declare(strict_types=1);
/**
 * Superadmin Dashboard - Platform Overview
 * STAMGAST Loyalty Platform
 */

require_once __DIR__ . '/../../models/Tenant.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$stats = $tenantModel->getPlatformStats();

// Get all tenants with user count
$tenants = $tenantModel->getAllWithUserCount();
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
    </div>

    <!-- Tenants List -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <div style="margin-bottom: var(--space-md);">
            <h2 style="margin-bottom: var(--space-sm);">Tenants</h2>
            <a href="/superadmin/tenants" class="btn btn-primary">Beheer Tenants</a>
        </div>

        <?php if (empty($tenants)): ?>
            <p class="text-secondary">Nog geen tenants aangemaakt.</p>
        <?php else: ?>
            <!-- Tenant Cards Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-md);">
                <?php foreach ($tenants as $t): ?>
                <a href="/superadmin/tenant/<?= (int) $t['id'] ?>" class="glass-card" style="display: block; padding: var(--space-md); text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid <?= sanitize($t['brand_color'] ?? '#FFC107') ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-sm);">
                        <h3 style="margin: 0; font-size: 18px; color: var(--text-primary);"><?= sanitize($t['name']) ?></h3>
                        <span class="badge" style="font-size: 11px;"><?= sanitize($t['mollie_status']) ?></span>
                    </div>
                    <p style="margin: 0 0 var(--space-sm); font-size: 13px; color: var(--text-secondary);"><code><?= sanitize($t['slug']) ?></code></p>
                    <div style="display: flex; gap: var(--space-md); font-size: 13px;">
                        <span style="color: var(--text-secondary);">
                            <strong style="color: var(--accent-primary);"><?= (int) $t['user_count'] ?></strong> gebruikers
                        </span>
                        <span style="color: var(--text-secondary);">
                            <strong style="color: var(--accent-primary);"><?= (int) $t['guest_count'] ?? 0 ?></strong> gasten
                        </span>
                        <span style="color: var(--text-secondary);">
                            <strong style="color: var(--accent-primary);"><?= (int) $t['staff_count'] ?? 0 ?></strong> staff
                        </span>
                    </div>
                    <div style="margin-top: var(--space-sm); font-size: 12px; color: var(--text-secondary);">
                        <?= $t['created_at'] ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Table View (collapsed by default, for reference) -->
            <details style="margin-top: var(--space-lg);">
                <summary style="cursor: pointer; color: var(--text-secondary); font-size: 14px;">Tabel weergave</summary>
                <table style="width: 100%; border-collapse: collapse; margin-top: var(--space-md);">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Naam</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Gebruikers</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Slug</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Mollie</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Aangemaakt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $t): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: var(--space-sm);">
                                <a href="/superadmin/tenant/<?= (int) $t['id'] ?>" style="color: var(--text-primary); text-decoration: underline;">
                                    <?= sanitize($t['name']) ?>
                                </a>
                            </td>
                            <td style="padding: var(--space-sm);"><?= (int) $t['user_count'] ?></td>
                            <td style="padding: var(--space-sm);"><code><?= sanitize($t['slug']) ?></code></td>
                            <td style="padding: var(--space-sm);"><span class="badge"><?= sanitize($t['mollie_status']) ?></span></td>
                            <td style="padding: var(--space-sm);"><?= $t['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
    </div>

    <div style="margin-top: var(--space-xl); text-align: center;">
        <a href="/logout" class="btn btn-secondary">Uitloggen</a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
