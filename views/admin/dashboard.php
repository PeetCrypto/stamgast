<?php
declare(strict_types=1);
/**
 * Admin Dashboard
 * STAMGAST Loyalty Platform
 */

$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Admin Dashboard - <?= sanitize($tenantName) ?></h1>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <a href="/admin/users" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px;">&#128101;</p>
            <p class="text-sm">Gebruikers</p>
        </a>
        <a href="/admin/tiers" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px;">&#127942;</p>
            <p class="text-sm">Tiers</p>
        </a>
        <a href="/admin/settings" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px;">&#9881;</p>
            <p class="text-sm">Instellingen</p>
        </a>
        <a href="/admin/marketing" class="glass-card" style="padding: var(--space-lg); text-align: center; text-decoration: none; color: inherit;">
            <p style="font-size: 24px;">&#128227;</p>
            <p class="text-sm">Marketing</p>
        </a>
    </div>

    <a href="/logout" class="btn btn-secondary">Uitloggen</a>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
