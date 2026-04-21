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

    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-lg); margin-bottom: var(--space-xl); max-width: 500px; margin: 0 auto var(--space-xl) auto;">
        <a href="/admin/users" class="glass-card" style="padding: var(--space-xl); text-align: center; text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; gap: var(--space-sm);">
            <div style="font-size: 48px;">&#128101;</div>
            <p class="text-sm" style="font-size: 18px; font-weight: 600;">Gebruikers</p>
        </a>
        <a href="/admin/tiers" class="glass-card" style="padding: var(--space-xl); text-align: center; text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; gap: var(--space-sm);">
            <div style="font-size: 48px;">&#127942;</div>
            <p class="text-sm" style="font-size: 18px; font-weight: 600;">Tiers</p>
        </a>
        <a href="/admin/settings" class="glass-card" style="padding: var(--space-xl); text-align: center; text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; gap: var(--space-sm);">
            <div style="font-size: 48px;">&#9881;</div>
            <p class="text-sm" style="font-size: 18px; font-weight: 600;">Instellingen</p>
        </a>
        <a href="/admin/marketing" class="glass-card" style="padding: var(--space-xl); text-align: center; text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; gap: var(--space-sm);">
            <div style="font-size: 48px;">&#128227;</div>
            <p class="text-sm" style="font-size: 18px; font-weight: 600;">Marketing</p>
        </a>
    </div>

    <div style="text-align: center;">
        <a href="/logout" class="btn btn-secondary">Uitloggen</a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
