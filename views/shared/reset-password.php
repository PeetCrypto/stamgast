<?php
declare(strict_types=1);
/**
 * Reset Password Page
 * User arrives here via email link: /reset-password?token=xxx or /j/{slug}/reset-password?token=xxx
 */

if (!isset($tenant) || !is_array($tenant)) {
    $tenant = [];
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo '<h1>Ongeldige link</h1><p>Deze wachtwoord-reset link is ongeldig.</p>';
    exit;
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
    <title>Wachtwoord resetten — <?= sanitize($tenantName) ?></title>

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
        .password-strength { height: 4px; background: var(--glass-border); border-radius: 2px; margin-top: 0.5rem; overflow: hidden; }
        .password-strength__bar { height: 100%; width: 0%; transition: width 0.3s ease, background-color 0.3s ease; }
        .password-strength__bar.weak { width: 33%; background: var(--error); }
        .password-strength__bar.medium { width: 66%; background: var(--warning); }
        .password-strength__bar.strong { width: 100%; background: var(--success); }
        .form-hint { display: block; margin-top: 0.25rem; }
        .text-success { color: var(--success); }
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
            <h1>Nieuw wachtwoord</h1>
            <p class="text-secondary text-sm">Kies een nieuw wachtwoord voor je account.</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error mb-2 animate-in"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <form id="reset-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="reset_token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="password">Nieuw wachtwoord</label>
                <input type="password" id="password" name="password" class="form-input"
                       placeholder="Minimaal 8 tekens" required minlength="8" autocomplete="new-password" autofocus>
                <div class="password-strength">
                    <div class="password-strength__bar" id="password-strength-bar"></div>
                </div>
                <small class="form-hint" id="password-hint">Gebruik minimaal 8 tekens</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Bevestig wachtwoord</label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                       placeholder="Herhaal je wachtwoord" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <span id="submit-text">Wachtwoord wijzigen</span>
                <svg id="submit-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" style="display:none;">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                    <path d="M10 2C5.58 2 2 5.58 2 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </form>

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
    const form = document.getElementById('reset-form');
    const btn = form.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submit-text');
    const submitIcon = document.getElementById('submit-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const token = document.getElementById('reset_token').value;

    // Password strength
    const passwordInput = document.getElementById('password');
    const strengthBar = document.getElementById('password-strength-bar');
    const passwordHint = document.getElementById('password-hint');

    passwordInput.addEventListener('input', () => {
        const p = passwordInput.value;
        const s = calcStrength(p);
        strengthBar.className = 'password-strength__bar';
        if (p.length > 0) {
            strengthBar.classList.add(s);
            updateHint(s);
        } else {
            strengthBar.style.width = '0%';
            passwordHint.textContent = 'Gebruik minimaal 8 tekens';
        }
    });

    function calcStrength(p) {
        let s = 0;
        if (p.length >= 8) s++;
        if (p.length >= 12) s++;
        if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
        if (/[0-9]/.test(p)) s++;
        if (/[^a-zA-Z0-9]/.test(p)) s++;
        if (s <= 1) return 'weak';
        if (s <= 3) return 'medium';
        return 'strong';
    }

    function updateHint(strength) {
        const hints = { weak: 'Zwak wachtwoord', medium: 'Gemiddelde sterkte', strong: 'Sterk wachtwoord!' };
        passwordHint.textContent = hints[strength];
        passwordHint.className = 'form-hint ' + (strength === 'strong' ? 'text-success' : '');
    }

    // Form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const password = document.getElementById('password').value;
        const confirm  = document.getElementById('password_confirm').value;

        if (!password || !confirm) { showError('Vul alle velden in.'); return; }
        if (password.length < 8) { showError('Wachtwoord moet minimaal 8 tekens lang zijn.'); return; }
        if (password !== confirm) { showError('Wachtwoorden komen niet overeen.'); return; }

        btn.disabled = true;
        submitText.textContent = 'Bezig...';
        submitIcon.style.display = 'block';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/reset-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ token: token, password: password, password_confirm: confirm })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                window.location.href = data.data.redirect || ((window.__BASE_URL || '') + '/login');
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
        submitText.textContent = 'Wachtwoord wijzigen';
        submitIcon.style.display = 'none';
    }
});
</script>
</body>
</html>
