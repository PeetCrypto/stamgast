<?php
declare(strict_types=1);
/**
 * Forgot Password Page
 * Supports both tenant slug context ($tenant set) and session context
 */

if (!isset($tenant) || !is_array($tenant)) {
    $tenant = [];
}

$tenantSlug   = $tenant['slug'] ?? '';
$tenantName   = $tenant['name'] ?? ($_SESSION['tenant_name'] ?? APP_NAME);
$brandColor   = $tenant['brand_color'] ?? ($_SESSION['brand_color'] ?? '#FFC107');
$secondary    = $tenant['secondary_color'] ?? ($_SESSION['secondary_color'] ?? '#FF9800');
$tenantLogo   = $tenant['logo_path'] ?? ($_SESSION['tenant_logo'] ?? '');
$csrfToken    = generateCSRFToken();

$backLink = !empty($tenantSlug) ? BASE_URL . '/j/' . sanitize($tenantSlug) : BASE_URL . '/login';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title>Wachtwoord vergeten — <?= sanitize($tenantName) ?></title>

    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/midnight-lounge.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/views.css">

    <style>
        :root {
            --accent-primary: <?= sanitize($brandColor) ?>;
            --accent-secondary: <?= sanitize($secondary) ?>;
            --accent-gradient: linear-gradient(135deg, <?= sanitize($brandColor) ?> 0%, <?= sanitize($secondary) ?> 100%);
        }
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--space-lg);
            background: linear-gradient(180deg, var(--bg-darkest) 0%, var(--bg-lightest) 100%);
        }
        .auth-container { width: 100%; max-width: 420px; }
        .auth-header { text-align: center; margin-bottom: var(--space-lg); }
        .auth-header h1 { font-size: 24px; margin-bottom: 0.25rem; }
        .auth-logo {
            text-align: center;
            margin-bottom: var(--space-md);
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .auth-logo img {
            max-height: 80px;
            max-width: 220px;
            object-fit: contain;
            border-radius: 8px;
            display: block;
        }
        .auth-form .btn { margin-top: var(--space-sm); }
        .auth-footer { text-align: center; margin-top: var(--space-lg); font-size: 14px; }
        .auth-footer a { font-weight: 600; color: var(--accent-primary); }
        .auth-tenant-info { margin-top: var(--space-xl); font-size: 12px; }
        .form-group { margin-bottom: 1.25rem; }
    </style>
</head>
<body class="auth-page">

<div class="auth-container">
    <div class="glass-card animate-in">

        <div class="auth-header">
            <div class="auth-logo">
                <?php if (!empty($tenantLogo)): ?>
                    <img src="<?= sanitize($tenantLogo) ?>" alt="<?= sanitize($tenantName) ?>">
                <?php else: ?>
                    <svg width="56" height="56" viewBox="0 0 48 48" fill="none">
                        <rect width="48" height="48" rx="12" fill="url(#brand-gradient)"/>
                        <path d="M16 24L22 30L32 18" stroke="#0f0f0f" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <defs>
                            <linearGradient id="brand-gradient" x1="0" y1="0" x2="48" y2="48">
                                <stop stop-color="<?= sanitize($brandColor) ?>"/>
                                <stop offset="1" stop-color="<?= sanitize($secondary) ?>"/>
                            </linearGradient>
                        </defs>
                    </svg>
                <?php endif; ?>
            </div>
            <h1>Wachtwoord vergeten</h1>
            <p class="text-secondary text-sm">Voer je e-mailadres in en we sturen je een reset-link.</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error mb-2 animate-in"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <form id="forgot-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="tenant_slug" value="<?= sanitize($tenantSlug) ?>">

            <div class="form-group">
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" class="form-input"
                       placeholder="jouw@email.nl" required autocomplete="email" autofocus>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <span id="submit-text">Reset-link versturen</span>
                <svg id="submit-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" style="display:none;">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                    <path d="M10 2C5.58 2 2 5.58 2 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </form>

        <!-- Success message (hidden by default) -->
        <div id="success-message" class="alert alert-success mb-2 animate-in" style="display:none; margin-top: var(--space-md);">
            Als dit e-mailadres bij ons bekend is, ontvang je een reset-link.
        </div>

        <div class="auth-footer">
            <p class="text-secondary text-sm">
                <a href="<?= $backLink ?>">Terug naar inloggen</a>
            </p>
        </div>

    </div>

    <div class="auth-tenant-info text-center">
        <p class="text-muted text-xs">
            <?= sanitize($tenantName) ?> &middot; REGULR.vip
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('forgot-form');
    const btn = form.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submit-text');
    const submitIcon = document.getElementById('submit-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const tenantSlug = document.getElementById('tenant_slug').value;
    const successMessage = document.getElementById('success-message');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const email = document.getElementById('email').value.trim();
        if (!email) { showError('Voer je e-mailadres in.'); return; }

        btn.disabled = true;
        submitText.textContent = 'Bezig...';
        submitIcon.style.display = 'block';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/forgot-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ email: email, tenant_slug: tenantSlug })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                form.style.display = 'none';
                successMessage.style.display = 'block';
            } else {
                showError(data.error || 'Er is iets misgegaan.');
                resetButton();
            }
        } catch (err) {
            showError('Netwerkfout. Controleer je verbinding.');
            resetButton();
        }
    });

    function showError(message) {
        const existing = document.querySelector('.alert-error');
        if (existing) existing.remove();
        const alert = document.createElement('div');
        alert.className = 'alert alert-error mb-2 animate-in';
        alert.textContent = message;
        form.insertBefore(alert, form.firstChild);
        setTimeout(() => { alert.remove(); }, 5000);
    }

    function resetButton() {
        btn.disabled = false;
        submitText.textContent = 'Reset-link versturen';
        submitIcon.style.display = 'none';
    }
});
</script>
</body>
</html>
