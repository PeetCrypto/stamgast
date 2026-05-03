<?php
declare(strict_types=1);
/**
 * Login Page - Midnight Lounge Design
 * REGULR.vip Loyalty Platform
 */

// Redirect if already logged in
if (isLoggedIn()) {
    $role = currentUserRole();
    $dashboardMap = [
        'superadmin' => '/superadmin',
        'admin'      => '/admin',
        'bartender'  => '/scan',
        'guest'      => '/dashboard',
    ];
    redirect($dashboardMap[$role] ?? '/dashboard');
}

$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$brandColor = $_SESSION['brand_color'] ?? '#FFC107';
$csrfToken  = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title>Inloggen - <?= sanitize($tenantName) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/icons/favicon.png">
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/favicon.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Midnight Lounge Design System -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/midnight-lounge.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/views.css">

    <!-- Tenant branding -->
    <style>
        :root {
            --accent-primary: <?= sanitize($brandColor) ?>;
            --accent-secondary: #FF9800;
            --accent-gradient: linear-gradient(135deg, <?= sanitize($brandColor) ?> 0%, #FF9800 100%);
        }
    </style>

    <!-- Auth-specific styles -->
    <style>
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

        .auth-logo {
            text-align: center;
            margin-bottom: var(--space-sm);
        }

        .auth-logo svg {
            display: inline-block;
        }

        .auth-subtitle {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
            font-size: 14px;
        }

        .auth-header {
            text-align: center;
            margin-bottom: var(--space-lg);
        }

        .auth-header h1 {
            font-size: 24px;
            margin-bottom: 0.25rem;
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
    </style>
</head>
<body class="auth-page">

<div class="auth-container">

    <!-- Login Card -->
    <div class="glass-card animate-in">

        <!-- Logo / Brand -->
        <div class="auth-header">
            <div class="auth-logo">
                <svg width="56" height="56" viewBox="0 0 48 48" fill="none">
                    <rect width="48" height="48" rx="12" fill="url(#brand-gradient)"/>
                    <path d="M16 24L22 30L32 18" stroke="#0f0f0f" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <defs>
                        <linearGradient id="brand-gradient" x1="0" y1="0" x2="48" y2="48">
                            <stop stop-color="#FFC107"/>
                            <stop offset="1" stop-color="#FF9800"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h1>Welkom terug</h1>
            <p class="text-secondary text-sm">Log in op je REGULR.vip account</p>
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
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="form-group">
                <label for="email">E-mailadres</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="jouw@email.nl"
                    required
                    autocomplete="email"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-input"
                    placeholder="••••••••"
                    required
                    autocomplete="current-password"
                >
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
            <p class="text-secondary text-sm">
                <a href="<?= BASE_URL ?>/forgot-password">Wachtwoord vergeten?</a>
            </p>
        </div>

    </div>

    <!-- Tenant Info -->
    <div class="auth-tenant-info text-center">
        <p class="text-muted text-xs">
            <span id="tenant-name"><?= sanitize($tenantName) ?></span> &middot; Loyaliteitsplatform
        </p>
    </div>

</div>

<!-- JavaScript -->
<script>
const BASE_URL = '<?= BASE_URL ?>';
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    const btn = form.querySelector('button[type="submit"]');
    const loginText = document.getElementById('login-text');
    const loginIcon = document.getElementById('login-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        // Client-side validation
        if (!email || !password) {
            showError('Vul alle velden in.');
            return;
        }

        // Set loading state
        btn.disabled = true;
        loginText.textContent = 'Bezig...';
        loginIcon.style.display = 'block';

        try {
            console.log('[LOGIN] Attempt:', { email, csrfToken: csrfToken ? 'present' : 'MISSING' });
            const response = await fetch(BASE_URL + '/api/auth/login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email: email,
                    password: password
                })
            });

            console.log('[LOGIN] Response status:', response.status, response.statusText);

            // Get raw text first to debug HTML errors
            const rawText = await response.text();
            console.log('[LOGIN] Raw response (first 500 chars):', rawText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                console.error('[LOGIN] JSON parse failed. Server returned non-JSON:', rawText.substring(0, 200));
                showError('Server fout - geen JSON ontvangen. Check PHP error log.');
                resetButton();
                return;
            }

            console.log('[LOGIN] Parsed data:', data);

            if (response.ok && data.success) {
                // Redirect to dashboard
                window.location.href = data.data.redirect || '/dashboard';
            } else {
                console.error('[LOGIN] Failed:', data.error, data.code);
                showError(data.error || 'Inloggen mislukt. Code: ' + (data.code || 'UNKNOWN'));
                resetButton();
            }
        } catch (err) {
            console.error('[LOGIN] Fetch error:', err);
            showError('Netwerkfout: ' + err.message);
            resetButton();
        }
    });

    function showError(message) {
        // Remove existing error
        const existing = document.querySelector('.alert-error');
        if (existing) existing.remove();

        const alert = document.createElement('div');
        alert.className = 'alert alert-error mb-2 animate-in';
        alert.textContent = message;

        form.insertBefore(alert, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            alert.remove();
        }, 5000);
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
