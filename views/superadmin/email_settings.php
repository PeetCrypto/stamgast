<?php
declare(strict_types=1);
/**
 * Superadmin Email Settings
 * Configure SMTP provider and credentials for the platform
 */

require_once __DIR__ . '/../../models/EmailConfig.php';

$db = Database::getInstance()->getConnection();
$emailConfig = new EmailConfig($db);
$config = $emailConfig->getActiveConfig();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'test_email') {
    $data = [
        'id'              => !empty($config['id']) ? (int)$config['id'] : null,
        'provider'        => $_POST['provider'] ?? 'brevo',
        'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'       => (int)($_POST['smtp_port'] ?? 587),
        'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
        'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'       => trim($_POST['smtp_pass'] ?? ''),
        'from_email'      => trim($_POST['from_email'] ?? 'no-reply@regulr.vip'),
        'from_name'       => trim($_POST['from_name'] ?? 'STAMGAST'),
        'is_active'       => 1,
    ];

    // If password field is empty, keep the existing one
    if (empty($data['smtp_pass']) && $config) {
        unset($data['smtp_pass']);
    }

    $saved = $emailConfig->saveConfig($data);
    $flash = $saved ? 'Email configuratie opgeslagen!' : 'Fout bij opslaan configuratie.';
    $flashType = $saved ? 'success' : 'error';

    // Reload config
    $config = $emailConfig->getActiveConfig();
}

// Handle test email
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_email') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!empty($testTo) && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $testResult = $emailConfig->testConfig($testTo);
    } else {
        $testResult = ['success' => false, 'message' => 'Ongeldig e-mailadres'];
    }
}
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>REGULR.vip Email Instellingen</h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary" style="width: auto;">&larr; Terug</a>
    </div>

    <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flashType ?>" style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
        <?= $flashType === 'success' ? 'background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);' : 'background: rgba(244,67,54,0.15); color: #f44336; border: 1px solid rgba(244,67,54,0.3);' ?>">
        <?= sanitize($flash) ?>
    </div>
    <?php endif; ?>

    <?php if ($testResult): ?>
    <div style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
        <?= $testResult['success'] ? 'background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);' : 'background: rgba(244,67,54,0.15); color: #f44336; border: 1px solid rgba(244,67,54,0.3);' ?>">
        <?= sanitize($testResult['message']) ?>
    </div>
    <?php endif; ?>

    <!-- SMTP Configuration Form -->
    <form class="glass-card" method="POST" action="" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">

        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">SMTP Configuratie</h2>

        <div class="form-group">
            <label>Email Provider</label>
            <select name="provider" class="form-input">
                <option value="brevo" <?= ($config['provider'] ?? 'brevo') === 'brevo' ? 'selected' : '' ?>>BREVO (Brevo)</option>
                <option value="sender_net" <?= ($config['provider'] ?? '') === 'sender_net' ? 'selected' : '' ?>>Sender.net</option>
                <option value="aws_ses" <?= ($config['provider'] ?? '') === 'aws_ses' ? 'selected' : '' ?>>Amazon SES</option>
            </select>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-md);">
            <div class="form-group">
                <label>SMTP Host *</label>
                <input type="text" name="smtp_host" class="form-input"
                       value="<?= sanitize($config['smtp_host'] ?? '') ?>"
                       placeholder="smtp-relay.brevo.com" required>
            </div>
            <div class="form-group">
                <label>Poort *</label>
                <input type="number" name="smtp_port" class="form-input"
                       value="<?= (int)($config['smtp_port'] ?? 587) ?>"
                       placeholder="587" required>
            </div>
        </div>

        <div class="form-group">
            <label>Encryptie</label>
            <select name="smtp_encryption" class="form-input">
                <option value="tls" <?= ($config['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (aanbevolen)</option>
                <option value="ssl" <?= ($config['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="starttls" <?= ($config['smtp_encryption'] ?? '') === 'starttls' ? 'selected' : '' ?>>STARTTLS</option>
                <option value="none" <?= ($config['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Geen</option>
            </select>
        </div>

        <div class="form-group">
            <label>SMTP Gebruikersnaam *</label>
            <input type="text" name="smtp_user" class="form-input"
                   value="<?= sanitize($config['smtp_user'] ?? '') ?>"
                   placeholder="user@brevo.com" required>
        </div>

        <div class="form-group">
            <label>SMTP Wachtwoord <?= $config ? '(leeg = huidige behouden)' : '*' ?></label>
            <input type="password" name="smtp_pass" class="form-input"
                   placeholder="<?= $config ? '••••••••' : 'Wachtwoord' ?>">
        </div>

        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: var(--space-lg) 0;">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
            <div class="form-group">
                <label>Afzender E-mail</label>
                <input type="email" name="from_email" class="form-input"
                       value="<?= sanitize($config['from_email'] ?? 'no-reply@regulr.vip') ?>">
            </div>
            <div class="form-group">
                <label>Afzender Naam</label>
                <input type="text" name="from_name" class="form-input"
                       value="<?= sanitize($config['from_name'] ?? 'STAMGAST') ?>">
            </div>
        </div>

        <div style="text-align: center; margin-top: var(--space-lg);">
            <button type="submit" class="btn btn-primary" style="width: auto; min-width: 200px;">Configuratie Opslaan</button>
        </div>
    </form>

    <!-- Test Email -->
    <form class="glass-card" method="POST" action="" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <input type="hidden" name="action" value="test_email">
        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Test Email Versturen</h2>
        <?php if ($config): ?>
        <div style="display: flex; gap: var(--space-md); align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <label>Ontvanger e-mailadres</label>
                <input type="email" name="test_email" class="form-input"
                       placeholder="jouw@email.nl" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: auto; white-space: nowrap;"> Verstuur Test</button>
        </div>
        <?php else: ?>
        <p class="text-secondary">Sla eerst een SMTP configuratie op voordat je een test email kunt versturen.</p>
        <?php endif; ?>
    </form>

    <!-- Email Templates Link -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Email Templates</h2>
        <p class="text-secondary" style="margin-bottom: var(--space-md);">
            Beheer de email templates voor het platform (tenant welcome, admin invite, etc.).
        </p>
        <a href="<?= BASE_URL ?>/superadmin/email-templates" class="btn btn-secondary" style="width: auto;">Beheer Templates</a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
