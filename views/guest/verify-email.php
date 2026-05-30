<?php
declare(strict_types=1);
/**
 * Email Verification Page — /j/{slug}/verify
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

// Must be logged in to verify email — redirect to login if not
if (!isLoggedIn()) {
    redirect('/j/' . $tenantSlug);
}

// Get user email from session (they should be logged in after registration)
$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$user = $userModel->findById(currentUserId());

if ($user === null) {
    // User not found in DB — session is stale, force logout
    redirect('/logout?return=' . urlencode('/j/' . $tenantSlug));
}

$userEmail = $user['email'] ?? '';

// If already verified, redirect to dashboard
if (!empty($user['email_verified_at'])) {
    redirect('/dashboard');
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title>Verifieer je e-mail — <?= sanitize($tenantName) ?></title>

    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= sanitize($tenantName) ?>">

    <!-- PWA Manifest -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json.php?slug=<?= urlencode($tenantSlug) ?>">
    <?php if (!empty($tenantLogo)): ?>
    <link rel="apple-touch-icon" href="<?= sanitize($tenantLogo) ?>">
    <?php else: ?>
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/favicon.png">
    <?php endif; ?>

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

        .auth-tenant-info {
            margin-top: var(--space-xl);
            font-size: 12px;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .code-input-group {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin: var(--space-lg) 0;
            max-width: 100%;
            padding: 0 4px;
        }

        .code-input {
            flex: 1;
            max-width: 48px;
            min-width: 32px;
            height: 56px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            font-family: 'Inter', monospace;
            background: rgba(255,255,255,0.05);
            border: 2px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s ease, background 0.2s ease;
            text-transform: uppercase;
        }

        .code-input:focus {
            border-color: var(--accent-primary);
            background: rgba(255,255,255,0.08);
        }

        .code-input.filled {
            border-color: var(--accent-primary);
            background: rgba(255,255,255,0.08);
        }

        .code-input.error {
            border-color: var(--error);
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        .resend-section {
            text-align: center;
            margin-top: var(--space-lg);
        }

        .resend-section p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 0.5rem;
        }

        .resend-btn {
            background: none;
            border: none;
            color: var(--accent-primary);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }

        .resend-btn:disabled {
            color: var(--text-muted);
            cursor: not-allowed;
            text-decoration: none;
        }

        .email-hint {
            color: var(--text-secondary);
            font-size: 14px;
            text-align: center;
            margin-bottom: var(--space-md);
        }

        .email-hint strong {
            color: var(--text-primary);
        }

        .success-icon {
            font-size: 48px;
            margin-bottom: var(--space-md);
        }
    </style>
</head>
<body class="auth-page">

<div class="auth-container">
    <div class="glass-card animate-in">

        <!-- Logo / Brand -->
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
            <h1>Verifieer je e-mail</h1>
            <p class="text-secondary text-sm">Bevestig je account bij <?= sanitize($tenantName) ?></p>
        </div>

        <!-- Error Alert -->
        <div id="error-alert" style="display:none;" class="alert alert-error mb-2 animate-in"></div>

        <!-- Success state (hidden by default) -->
        <div id="success-state" style="display:none; text-align: center; padding: var(--space-lg);">
            <div class="success-icon">&#9989;</div>
            <h2 style="font-size: 20px; margin-bottom: 0.5rem;">E-mail geverifieerd!</h2>
            <p class="text-secondary text-sm" style="margin-bottom: var(--space-lg);">Je account is bevestigd. Je wordt doorgestuurd...</p>
        </div>

        <!-- Verification Form -->
        <div id="verify-form">
            <p class="email-hint">
                We hebben een verificatiecode gestuurd naar<br>
                <strong><?= sanitize($userEmail) ?></strong>
            </p>

            <form id="code-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" id="tenant_slug" value="<?= sanitize($tenantSlug) ?>">

                <div class="code-input-group">
                    <input type="text" class="code-input" maxlength="1" data-index="0" autocomplete="off" autofocus inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="1" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="2" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="3" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="4" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="5" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="6" autocomplete="off" inputmode="text">
                    <input type="text" class="code-input" maxlength="1" data-index="7" autocomplete="off" inputmode="text">
                </div>

                <button type="submit" id="verify-btn" class="btn btn-primary btn-lg" style="width: 100%;">
                    <span id="verify-text">Verifiëren</span>
                    <svg id="verify-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" style="display:none;">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                        <path d="M10 2C5.58 2 2 5.58 2 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </form>

            <div class="resend-section">
                <p>Geen code ontvangen?</p>
                <button id="resend-btn" class="resend-btn">Code opnieuw versturen</button>
                <p id="resend-timer" style="display:none; color: var(--text-muted); font-size: 13px;"></p>
            </div>
        </div>

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
    const inputs = document.querySelectorAll('.code-input');
    const form = document.getElementById('code-form');
    const verifyBtn = document.getElementById('verify-btn');
    const verifyText = document.getElementById('verify-text');
    const verifyIcon = document.getElementById('verify-icon');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const tenantSlug = document.getElementById('tenant_slug').value;
    const resendBtn = document.getElementById('resend-btn');
    const resendTimer = document.getElementById('resend-timer');
    const errorAlert = document.getElementById('error-alert');
    const successState = document.getElementById('success-state');
    const verifyForm = document.getElementById('verify-form');

    // --- Code input behavior: auto-advance, paste support ---
    inputs.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            const value = e.target.value.toUpperCase();
            e.target.value = value;

            if (value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }

            // Visual feedback
            if (value) {
                e.target.classList.add('filled');
            } else {
                e.target.classList.remove('filled');
            }

            // Auto-submit when all filled
            const code = getCode();
            if (code.length === 8) {
                submitCode(code);
            }
        });

        input.addEventListener('keydown', (e) => {
            // Backspace: go to previous input
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                inputs[index - 1].focus();
                inputs[index - 1].value = '';
                inputs[index - 1].classList.remove('filled');
            }
            // Arrow left/right navigation
            if (e.key === 'ArrowLeft' && index > 0) {
                inputs[index - 1].focus();
            }
            if (e.key === 'ArrowRight' && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        });

        // Handle paste: fill all inputs from pasted text
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').toUpperCase().replace(/[^A-Z0-9]/g, '');
            for (let i = 0; i < Math.min(pasted.length, inputs.length); i++) {
                inputs[i].value = pasted[i];
                inputs[i].classList.add('filled');
            }
            const focusIndex = Math.min(pasted.length, inputs.length - 1);
            inputs[focusIndex].focus();

            if (pasted.length >= 8) {
                submitCode(pasted.substring(0, 8));
            }
        });

        // Select all text on focus
        input.addEventListener('focus', () => {
            input.select();
        });
    });

    // Form submit handler
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const code = getCode();
        if (code.length < 8) {
            showError('Vul alle 8 tekens in.');
            return;
        }
        submitCode(code);
    });

    function getCode() {
        return Array.from(inputs).map(i => i.value).join('');
    }

    async function submitCode(code) {
        hideError();
        verifyBtn.disabled = true;
        verifyText.textContent = 'Bezig...';
        verifyIcon.style.display = 'block';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/verify-email', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    code: code,
                    tenant_slug: tenantSlug
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Show success state
                verifyForm.style.display = 'none';
                successState.style.display = 'block';

                // Redirect to dashboard after short delay
                setTimeout(() => {
                    window.location.href = data.data.redirect || ((window.__BASE_URL || '') + '/dashboard');
                }, 1500);
            } else {
                showError(data.error || 'Ongeldige code. Probeer opnieuw.');
                resetButton();
                // Shake inputs
                inputs.forEach(i => {
                    i.classList.add('error');
                    setTimeout(() => i.classList.remove('error'), 500);
                });
                // Clear inputs
                inputs.forEach(i => { i.value = ''; i.classList.remove('filled'); });
                inputs[0].focus();
            }
        } catch (err) {
            showError('Netwerkfout. Controleer je verbinding.');
            resetButton();
        }
    }

    // --- Resend code ---
    let resendCooldown = 0;
    let cooldownInterval = null;

    resendBtn.addEventListener('click', async () => {
        if (resendCooldown > 0) return;

        resendBtn.disabled = true;
        resendBtn.textContent = 'Bezig...';

        try {
            const response = await fetch((window.__BASE_URL || '') + '/api/auth/resend-verification', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    tenant_slug: tenantSlug
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                hideError();
                startCooldown(60); // 60 seconden cooldown
            } else {
                showError(data.error || 'Kon code niet opnieuw versturen.');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Code opnieuw versturen';
            }
        } catch (err) {
            showError('Netwerkfout. Controleer je verbinding.');
            resendBtn.disabled = false;
            resendBtn.textContent = 'Code opnieuw versturen';
        }
    });

    function startCooldown(seconds) {
        resendCooldown = seconds;
        resendBtn.style.display = 'none';
        resendTimer.style.display = 'block';

        cooldownInterval = setInterval(() => {
            resendCooldown--;
            if (resendCooldown <= 0) {
                clearInterval(cooldownInterval);
                resendBtn.style.display = 'inline';
                resendBtn.disabled = false;
                resendBtn.textContent = 'Code opnieuw versturen';
                resendTimer.style.display = 'none';
            } else {
                resendTimer.textContent = 'Nieuwe code over ' + resendCooldown + ' seconden';
            }
        }, 1000);
    }

    function showError(message) {
        errorAlert.textContent = message;
        errorAlert.style.display = 'block';
    }

    function hideError() {
        errorAlert.style.display = 'none';
    }

    function resetButton() {
        verifyBtn.disabled = false;
        verifyText.textContent = 'Verifiëren';
        verifyIcon.style.display = 'none';
    }
});
</script>

</body>
</html>
