<?php
declare(strict_types=1);
/**
 * Registration Page - Midnight Lounge Design
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
    <title>Registreren - <?= sanitize($tenantName) ?></title>

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

    <!-- Tenant branding + auth page styles -->
    <style>
        :root {
            --accent-primary: <?= sanitize($brandColor) ?>;
            --accent-secondary: #FF9800;
            --accent-gradient: linear-gradient(135deg, <?= sanitize($brandColor) ?> 0%, #FF9800 100%);
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

        .age-warning {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-md);
        }

        .form-hint {
            display: block;
            margin-top: 0.25rem;
        }

        .text-success {
            color: var(--success);
        }

        /* Password strength indicator */
        .password-strength {
            height: 4px;
            background: var(--glass-border);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .password-strength__bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-strength__bar.weak {
            width: 33%;
            background: var(--error);
        }

        .password-strength__bar.medium {
            width: 66%;
            background: var(--warning);
        }

        .password-strength__bar.strong {
            width: 100%;
            background: var(--success);
        }

        .form-group {
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body class="auth-page">

<div class="auth-container">

    <!-- Registration Card -->
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
            <h1>Account aanmaken</h1>
            <p class="text-secondary text-sm">Wordt lid van <?= sanitize($tenantName) ?></p>
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

        <!-- Registration Form -->
        <form id="register-form" class="auth-form" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <!-- Name Fields -->
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">Voornaam</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        class="form-input"
                        placeholder="Jan"
                        required
                        minlength="2"
                        maxlength="100"
                        autocomplete="given-name"
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="last_name">Achternaam</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        class="form-input"
                        placeholder="Jansen"
                        required
                        minlength="2"
                        maxlength="100"
                        autocomplete="family-name"
                    >
                </div>
            </div>

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
                >
            </div>

            <div class="form-group">
                <label for="birthdate">Geboortedatum</label>
                <input
                    type="date"
                    id="birthdate"
                    name="birthdate"
                    class="form-input"
                    required
                    autocomplete="bday"
                >
                <p class="age-warning">Je moet minimaal 18 jaar oud zijn om alcohol te bestellen.</p>
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-input"
                    placeholder="Minimaal 8 tekens"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
                <div class="password-strength">
                    <div class="password-strength__bar" id="password-strength-bar"></div>
                </div>
                <small class="form-hint" id="password-hint">Gebruik minimaal 8 tekens</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Bevestig wachtwoord</label>
                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    class="form-input"
                    placeholder="Herhaal je wachtwoord"
                    required
                    autocomplete="new-password"
                >
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <span id="register-text">Account aanmaken</span>
                <svg id="register-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" style="display:none;">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                    <path d="M10 2C5.58 2 2 5.58 2 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </form>

        <!-- Footer Links -->
        <div class="auth-footer">
            <p class="text-secondary text-sm">
                Al een account? <a href="<?= BASE_URL ?>/login">Inloggen</a>
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
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('register-form');
    const btn = form.querySelector('button[type="submit"]');
    const registerText = document.getElementById('register-text');
    const registerIcon = document.getElementById('register-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    // Password strength indicator
    const passwordInput = document.getElementById('password');
    const strengthBar = document.getElementById('password-strength-bar');
    const passwordHint = document.getElementById('password-hint');

    passwordInput.addEventListener('input', () => {
        const password = passwordInput.value;
        const strength = calculatePasswordStrength(password);

        // Update strength bar
        strengthBar.className = 'password-strength__bar';
        if (password.length > 0) {
            strengthBar.classList.add(strength);
            updateHint(strength);
        } else {
            strengthBar.style.width = '0%';
            passwordHint.textContent = 'Gebruik minimaal 8 tekens';
        }
    });

    function calculatePasswordStrength(password) {
        let score = 0;

        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;

        if (score <= 1) return 'weak';
        if (score <= 3) return 'medium';
        return 'strong';
    }

    function updateHint(strength) {
        const hints = {
            weak: 'Zwak wachtwoord - voeg meer tekens, hoofdletters en cijfers toe',
            medium: 'Gemiddelde sterkte - kan sterker',
            strong: 'Sterk wachtwoord!'
        };
        passwordHint.textContent = hints[strength];
        passwordHint.className = 'form-hint ' + (strength === 'strong' ? 'text-success' : '');
    }

    // Form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const firstName  = document.getElementById('first_name').value.trim();
        const lastName   = document.getElementById('last_name').value.trim();
        const email      = document.getElementById('email').value.trim();
        const birthdate  = document.getElementById('birthdate').value;
        const password   = document.getElementById('password').value;
        const confirm    = document.getElementById('password_confirm').value;

        // Client-side validation
        if (!firstName || !lastName || !email || !birthdate || !password || !confirm) {
            showError('Vul alle velden in.');
            return;
        }

        // Email format validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            showError('Ongeldig e-mailadres.');
            return;
        }

        // Age validation - 18+ check
        const birthDate = new Date(birthdate);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }

        if (age < 15) {
            showError('Je moet minimaal 18 jaar oud zijn om alcohol te bestellen.');
            return;
        }

        // Password validation
        if (password.length < 8) {
            showError('Wachtwoord moet minimaal 8 tekens lang zijn.');
            return;
        }

        if (password !== confirm) {
            showError('Wachtwoorden komen niet overeen.');
            return;
        }

        // Set loading state
        btn.disabled = true;
        registerText.textContent = 'Bezig...';
        registerIcon.style.display = 'block';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    first_name: firstName,
                    last_name: lastName,
                    email: email,
                    birthdate: birthdate,
                    password: password
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Redirect to dashboard
                window.location.href = data.redirect || ((window.__BASE_URL || '') + '/dashboard');
            } else {
                showError(data.error || 'Registratie mislukt.');
                resetButton();
            }
        } catch (err) {
            showError('Netwerkfout. Controleer je verbinding.');
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
        registerText.textContent = 'Account aanmaken';
        registerIcon.style.display = 'none';
    }

    // Set max date for birthdate picker to today minus 18 years
    const birthdateInput = document.getElementById('birthdate');
    const maxDate = new Date();
    maxDate.setFullYear(maxDate.getFullYear() - 15);
    birthdateInput.max = maxDate.toISOString().split('T')[0];

    // Set default date to 25 years ago for convenience
    const defaultDate = new Date();
    defaultDate.setFullYear(defaultDate.getFullYear() - 25);
    birthdateInput.value = defaultDate.toISOString().split('T')[0];
});
</script>

</body>
</html>
