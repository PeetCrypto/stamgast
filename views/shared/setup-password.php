<?php
/**
 * Setup Password Page
 * Allows invited users to set their own password via a one-time magic link.
 * URL: /setup-password?token=XXX
 *
 * This replaces the old flow where plaintext passwords were sent via email.
 */
require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/helpers.php';

$token = trim($_GET['token'] ?? '');
$hideForm = false;
$errorMsg = '';

if (empty($token)) {
    $hideForm = true;
    $errorMsg = 'Geen setup token gevonden. Controleer de link in je e-mail.';
}

$pageTitle = 'Wachtwoord instellen — REGULR.vip';
$cssPath = '/css/auth.css';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/auth.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f1a;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-container {
            max-width: 420px;
            width: 100%;
            background: #1a1a2e;
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .setup-container h1 {
            color: #FFC107;
            font-size: 24px;
            margin-bottom: 8px;
            text-align: center;
        }
        .setup-container p.subtitle {
            color: #888;
            font-size: 14px;
            text-align: center;
            margin-bottom: 32px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-size: 14px;
            color: #aaa;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            background: #16213e;
            border: 1px solid #2a2a4a;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus { border-color: #FFC107; }
        .form-group .hint {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #FFC107;
            color: #000;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error { background: rgba(244,67,54,0.15); color: #f44336; border: 1px solid rgba(244,67,54,0.3); }
        .alert-success { background: rgba(76,175,80,0.15); color: #4caf50; border: 1px solid rgba(76,175,80,0.3); }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo span {
            font-size: 28px;
            font-weight: 800;
            color: #FFC107;
            letter-spacing: -1px;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="logo"><span>REGULR.vip</span></div>

        <h1>Wachtwoord instellen</h1>
        <p class="subtitle">Stel je wachtwoord in om je account te activeren</p>

        <?php if ($errorMsg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if (!$hideForm): ?>
        <div id="alert-container"></div>

        <form id="setupForm" autocomplete="off">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="password">Nieuw wachtwoord</label>
                <input type="password" id="password" name="password" required
                       minlength="8" placeholder="Minimaal 8 tekens, 1 hoofdletter, 1 cijfer"
                       autocomplete="new-password">
                <div class="hint">Minimaal 8 tekens, met minimaal 1 hoofdletter en 1 cijfer</div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Bevestig wachtwoord</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       minlength="8" placeholder="Herhaal je wachtwoord"
                       autocomplete="new-password">
            </div>

            <button type="submit" class="btn" id="submitBtn">Wachtwoord instellen</button>
        </form>
        <?php endif; ?>
    </div>

    <script>
    document.getElementById('setupForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const alertContainer = document.getElementById('alert-container');
        const password = document.getElementById('password').value;
        const passwordConfirm = document.getElementById('password_confirm').value;
        const token = this.token.value;

        alertContainer.innerHTML = '';

        if (password !== passwordConfirm) {
            alertContainer.innerHTML = '<div class="alert alert-error">Wachtwoorden komen niet overeen.</div>';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Bezig...';

        try {
            const res = await fetch('<?= BASE_URL ?>/api/auth/setup-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token, password })
            });
            const data = await res.json();

            if (data.success) {
                alertContainer.innerHTML = '<div class="alert alert-success">' +
                    (data.message || 'Wachtwoord ingesteld!') + '</div>';
                document.getElementById('setupForm').style.display = 'none';
                setTimeout(() => {
                    window.location.href = data.login_url || '<?= BASE_URL ?>/login';
                }, 2000);
            } else {
                alertContainer.innerHTML = '<div class="alert alert-error">' +
                    (data.error || 'Er ging iets mis.') + '</div>';
                btn.disabled = false;
                btn.textContent = 'Wachtwoord instellen';
            }
        } catch (err) {
            alertContainer.innerHTML = '<div class="alert alert-error">Netwerkfout. Probeer opnieuw.</div>';
            btn.disabled = false;
            btn.textContent = 'Wachtwoord instellen';
        }
    });
    </script>
</body>
</html>
