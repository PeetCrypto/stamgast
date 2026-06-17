<?php
declare(strict_types=1);

/**
 * Emergency Token Generator — Web-based (superadmin only)
 * 
 * Access: /superadmin/emergency-token (via superadmin dashboard)
 * 
 * Generates a new emergency token and displays it ONCE.
 * The previous token (if any) is automatically replaced.
 * 
 * Session/auth is already handled by the view router in index.php.
 */

// Only superadmin can access (session already started by index.php)
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    die('403 — Alleen superadmin');
}

$token = null;
$error = null;

// Ensure CSRF token exists in session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        $error = 'CSRF token ongeldig. Probeer opnieuw.';
    } else {
    // Generate new token
    $token = bin2hex(random_bytes(32));
    // Use Argon2id if available, fallback to bcrypt (PASSWORD_DEFAULT)
    $hashAlgo = PASSWORD_DEFAULT;
    $hash = password_hash($token, $hashAlgo);

    if ($hash === false) {
        $error = 'Token generatie mislukt.';
    } else {
        try {
            $db = Database::getInstance()->getConnection();

            // Ensure platform_settings table exists
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `platform_settings` (
                    `id`            INT AUTO_INCREMENT PRIMARY KEY,
                    `setting_key`   VARCHAR(128)   NOT NULL UNIQUE,
                    `setting_value` TEXT           DEFAULT NULL,
                    `encrypted`     TINYINT(1)     NOT NULL DEFAULT 0,
                    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Upsert the emergency token hash
            $stmt = $db->prepare(
                "INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`)
                 VALUES ('emergency_token_hash', :hash, 1)
                 ON DUPLICATE KEY UPDATE `setting_value` = :hash2, `updated_at` = NOW()"
            );
            $stmt->execute([':hash' => $hash, ':hash2' => $hash]);

            // Audit log
            require_once __DIR__ . '/../../utils/audit.php';
            $audit = new Audit($db);
            $audit->log(null, (int) $_SESSION['user_id'], 'emergency.token_generated', 'system', null);

        } catch (\Throwable $e) {
            $error = 'Database fout: ' . $e->getMessage();
            $token = null;
        }
    }
    } // end else (CSRF valid)
}

// Check current token status
$hasActiveToken = false;
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'emergency_token_hash' AND setting_value != '' LIMIT 1");
    $stmt->execute();
    $hasActiveToken = (bool) $stmt->fetchColumn();
} catch (\Throwable $e) {
    // Table may not exist yet
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Token — REGULR.vip</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0d1117; color: #c9d1d9; padding: 24px; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { color: #e6edf3; font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #8b949e; margin-bottom: 24px; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 24px; margin-bottom: 16px; }
        .status { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; }
        .status-dot.active { background: #3fb950; }
        .status-dot.inactive { background: #f85149; }
        .status-text { font-size: 14px; }
        .status-text.active { color: #3fb950; }
        .status-text.inactive { color: #f85149; }
        .btn { display: inline-block; padding: 12px 24px; background: #f85149; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #da3633; }
        .btn-secondary { background: #21262d; color: #c9d1d9; border: 1px solid #30363d; }
        .btn-secondary:hover { background: #30363d; }
        .token-display { background: #0d1117; border: 2px solid #f85149; border-radius: 8px; padding: 20px; margin: 16px 0; text-align: center; }
        .token-label { color: #f85149; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }
        .token-value { font-family: 'SF Mono', 'Cascadia Code', monospace; font-size: 13px; color: #e6edf3; word-break: break-all; line-height: 1.8; }
        .warning { background: rgba(248, 81, 73, 0.1); border: 1px solid rgba(248, 81, 73, 0.3); border-radius: 6px; padding: 16px; margin: 16px 0; }
        .warning-title { color: #f85149; font-weight: 600; margin-bottom: 8px; }
        .warning-text { color: #8b949e; font-size: 13px; }
        .steps { counter-reset: step; }
        .step { display: flex; gap: 12px; margin-bottom: 12px; font-size: 13px; color: #8b949e; }
        .step::before { counter-increment: step; content: counter(step); display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #21262d; border-radius: 50%; font-size: 12px; font-weight: 600; color: #c9d1d9; flex-shrink: 0; }
        .error { background: rgba(248, 81, 73, 0.1); border: 1px solid rgba(248, 81, 73, 0.3); border-radius: 6px; padding: 16px; color: #f85149; }
        .back-link { display: inline-block; margin-top: 16px; color: #58a6ff; text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 Emergency Token</h1>
        <p class="subtitle">Break-glass toegang voor superadmin account</p>

        <?php if ($error): ?>
            <div class="card error">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($token): ?>
            <div class="card">
                <div class="token-display">
                    <div class="token-label">⚠️ Token — bewaar deze NU</div>
                    <div class="token-value"><?= htmlspecialchars($token) ?></div>
                </div>

                <div class="warning">
                    <div class="warning-title">Belangrijk</div>
                    <div class="warning-text">
                        Dit token wordt <strong>eenmalig</strong> getoond. Na gebruik wordt het automatisch geïnvalideerd.
                        Bewaar het in een wachtwoordbeheerder.
                    </div>
                </div>

                <div class="steps">
                    <div class="step">Ga naar /login</div>
                    <div class="step">Voer je superadmin e-mail in</div>
                    <div class="step">Plak het token als wachtwoord</div>
                    <div class="step">Je bent ingelogd — stel direct een nieuw wachtwoord in</div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="status">
                    <div class="status-dot <?= $hasActiveToken ? 'active' : 'inactive' ?>"></div>
                    <span class="status-text <?= $hasActiveToken ? 'active' : 'inactive' ?>">
                        <?= $hasActiveToken ? 'Er is een actief emergency token' : 'Geen actief emergency token' ?>
                    </span>
                </div>

                <p style="color: #8b949e; font-size: 14px; margin-bottom: 16px;">
                    Genereer een emergency token om toegang te krijgen tot je superadmin account als je wachtwoord vergeten is of corrupt is.
                    Het eventuele bestaande token wordt vervangen.
                </p>

                <form method="POST" onsubmit="return confirm('Weet je zeker dat je een nieuw emergency token wilt genereren? Het bestaande token (indien aanwezig) wordt ongeldig gemaakt.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <button type="submit" class="btn">🔑 Genereer Emergency Token</button>
                </form>
            </div>
        <?php endif; ?>

        <a href="/superadmin" class="back-link">← Terug naar dashboard</a>
    </div>
</body>
</html>
