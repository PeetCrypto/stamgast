<?php
declare(strict_types=1);
/**
 * Guest Login Page — Tenant-branded (via /j/{slug})
 * Self-contained: tenant context from $tenant (DB via slug), NOT from $_SESSION
 *
 * Expected variables (set by index.php route handler):
 *   $tenant — array from Tenant::findBySlug()
 */

$tenantName   = $tenant['name'] ?? APP_NAME;
$tenantSlug   = $tenant['slug'] ?? '';
$brandColor   = $tenant['brand_color'] ?? '#FFC107';
$secondary    = $tenant['secondary_color'] ?? '#FF9800';
$tenantLogo   = $tenant['logo_path'] ?? '';
$csrfToken    = generateCSRFToken();

// Edge case: user is logged in at a DIFFERENT tenant
$crossTenant  = isLoggedIn() && currentTenantId() !== null && currentTenantId() !== (int) $tenant['id'];
$sessionTenantName = $_SESSION['tenant_name'] ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title>Inloggen — <?= sanitize($tenantName) ?></title>

    <!-- PWA Manifest (tenant-branded) -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json.php">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/favicon.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Midnight Lounge Design System -->
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

        .auth-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-header {
            text-align: center;
            margin-bottom: var(--space-lg);
        }

        .auth-header h1 {
            font-size: 24px;
            margin-bottom: 0.25rem;
        }

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

        .auth-form .btn {
            margin-top: var(--space-sm);
        }

        .auth-footer {
            text-align: center;
            margin-top: var(--space-lg);
            font-size: 14px;
        }

        .auth-footer a {
            font-weight: 600;
            color: var(--accent-primary);
        }

        .auth-tenant-info {
            margin-top: var(--space-xl);
            font-size: 12px;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        /* Cross-tenant banner */
        .cross-tenant-banner {
            text-align: center;
            padding: var(--space-lg);
        }

        .cross-tenant-banner p {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="auth-page">

<div class="auth-container">

    <div class="glass-card animate-in">

        <?php if ($crossTenant): ?>
        <!-- ============================================================ -->
        <!-- EDGE CASE: Already logged in at a DIFFERENT tenant           -->
        <!-- ============================================================ -->
        <div class="cross-tenant-banner">
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
            <h1 style="font-size: 20px; margin-top: var(--space-md);">Je bent al lid bij <?= sanitize($sessionTenantName) ?></h1>
            <p class="text-secondary text-sm">
                Om in te loggen bij <strong><?= sanitize($tenantName) ?></strong> moet je eerst uitloggen bij je huidige locatie.
            </p>
            <a href="<?= BASE_URL ?>/logout?return=<?= urlencode('/j/' . $tenantSlug) ?>" class="btn btn-primary" style="margin-top: var(--space-md);">Uitloggen</a>
        </div>

        <?php else: ?>
        <!-- ============================================================ -->
        <!-- NORMAL: Login form (anonymous user)                          -->
        <!-- ============================================================ -->

        <!-- Logo / Brand — centered -->
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
            <h1>Welkom terug</h1>
            <p class="text-secondary text-sm">Log in bij <?= sanitize($tenantName) ?></p>
        </div>

        <!-- Error/Success Alerts -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error mb-2 animate-in">
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success mb-2 animate-in">
                <?= htmlspecialchars($_GET['success']) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="login-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="tenant_slug" value="<?= sanitize($tenantSlug) ?>">

            <div class="form-group">
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" class="form-input"
                       placeholder="jouw@email.nl" required autocomplete="email" autofocus>
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" class="form-input"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <span id="login-text">Inloggen</span>
                <svg id="login-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" style="display:none;">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                    <path d="M10 2C5.58 2 2 5.58 2 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </form>

        <!-- Footer Links -->
        <div class="auth-footer">
            <p class="text-secondary text-sm" style="margin-bottom: 0.5rem;">
                <a href="<?= BASE_URL ?>/j/<?= sanitize($tenantSlug) ?>/forgot-password">Wachtwoord vergeten?</a>
            </p>
            <p class="text-secondary text-sm">
                Nog geen account? <a href="<?= BASE_URL ?>/j/<?= sanitize($tenantSlug) ?>/register">Registeren</a>
            </p>
        </div>

        <?php endif; ?>

    </div>

    <!-- Tenant Info -->
    <div class="auth-tenant-info text-center">
        <p class="text-muted text-xs">
            <span id="tenant-name"><?= sanitize($tenantName) ?></span> &middot; REGULR.vip
        </p>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    if (!form) return; // Cross-tenant view has no form

    const btn = form.querySelector('button[type="submit"]');
    const loginText = document.getElementById('login-text');
    const loginIcon = document.getElementById('login-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const tenantSlug = document.getElementById('tenant_slug').value;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        if (!email || !password) {
            showError('Vul alle velden in.');
            return;
        }

        btn.disabled = true;
        loginText.textContent = 'Bezig...';
        loginIcon.style.display = 'block';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email: email,
                    password: password,
                    tenant_slug: tenantSlug
                })
            });

            let data;
            try {
                data = await response.json();
            } catch (parseErr) {
                showError('Server fout. Probeer opnieuw.');
                resetButton();
                return;
            }

            if (response.ok && data.success) {
                window.location.href = data.data.redirect || ((window.__BASE_URL || '') + '/dashboard');
            } else {
                showError(data.error || 'Inloggen mislukt.');
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
        loginText.textContent = 'Inloggen';
        loginIcon.style.display = 'none';
    }
});
</script>

</body>
</html>
