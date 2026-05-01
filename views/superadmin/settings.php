<?php
declare(strict_types=1);
/**
 * Superadmin Settings - Combined Tabbed View
 * Contains Mollie Connect, Email Settings, and Email Templates
 */

require_once __DIR__ . '/../../models/PlatformSetting.php';
require_once __DIR__ . '/../../models/EmailConfig.php';
require_once __DIR__ . '/../../models/EmailTemplate.php';

$db = Database::getInstance()->getConnection();
$ps = new PlatformSetting($db);
$emailConfig = new EmailConfig($db);
$emailTemplate = new EmailTemplate($db);

// Build settings lookup
$rows = $ps->getAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get active email config
$config = $emailConfig->getActiveConfig();

// Initialize flags
$platformSettingsSaved = false;
$emailSettingsSaved = false;
$testResult = null;

// Handle Platform Settings form submission
if (isset($_POST['platform_settings_action'])) {
    $fields = [
        'mollie_mode_default'          => trim($_POST['mollie_mode_default'] ?? 'mock'),
        'mollie_connect_client_id'     => trim($_POST['mollie_connect_client_id'] ?? ''),
        'mollie_connect_client_secret' => trim($_POST['mollie_connect_client_secret'] ?? ''),
        'mollie_connect_api_key'       => trim($_POST['mollie_connect_api_key'] ?? ''),
    ];

    if (!in_array($fields['mollie_mode_default'], ['mock', 'test', 'live'], true)) {
        $fields['mollie_mode_default'] = 'mock';
    }

    $secretKeys = ['mollie_connect_api_key', 'mollie_connect_client_secret'];
    $allSaved = true;

    foreach ($fields as $key => $value) {
        if (in_array($key, $secretKeys) && $value === '') {
            continue;
        }
        if (!$ps->set($key, $value)) {
            $allSaved = false;
        }
    }

    // Audit log
    $audit = new Audit($db);
    $audit->log(0, $_SESSION['user_id'] ?? 0, 'platform_settings.updated', 'platform_setting', 0, ['via' => 'settings_page']);

    $platformSettingsSaved = $allSaved;

    // Reload settings
    $rows = $ps->getAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Handle Email Settings form submission
if (isset($_POST['email_settings_action'])) {
    $data = [
        'id'              => $config['id'] ?? null,
        'provider'        => $_POST['provider'] ?? 'brevo',
        'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'       => (int)($_POST['smtp_port'] ?? 587),
        'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
        'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'       => trim($_POST['smtp_pass'] ?? ''),
        'from_email'      => trim($_POST['from_email'] ?? 'no-reply@regulr.vip'),
        'from_name'       => trim($_POST['from_name'] ?? 'REGULR.vip'),
        'is_active'       => 1,
    ];

    if (empty($data['smtp_pass']) && !empty($config)) {
        unset($data['smtp_pass']);
    }

    $saved = $emailConfig->saveConfig($data);
    $emailSettingsSaved = $saved;

    // Reload config
    $config = $emailConfig->getActiveConfig();
}

// Handle test email
if (isset($_POST['test_email_action'])) {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!empty($testTo) && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $testResult = $emailConfig->testConfig($testTo);
    } else {
        $testResult = ['success' => false, 'message' => 'Ongeldig e-mailadres'];
    }
}

// Handle Email Template form submission (POST-Redirect-GET pattern)
if (isset($_POST['email_template_action'])) {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $data = [
            'type'          => $_POST['type'] ?? 'tenant_welcome',
            'subject'       => trim($_POST['subject'] ?? ''),
            'content'       => $_POST['content'] ?? '',
            'text_content'  => trim($_POST['text_content'] ?? ''),
            'language_code' => $_POST['language_code'] ?? 'nl',
            'is_default'    => 1,
            'tenant_id'     => null,
        ];
        $id = $_POST['id'] ?? '';
        if (!empty($id)) {
            $data['id'] = (int)$id;
        }

        $ok = $emailTemplate->saveTemplate($data);
        $_SESSION['flash']      = $ok ? 'Template opgeslagen!' : 'Fout bij opslaan template.';
        $_SESSION['flash_type'] = $ok ? 'success' : 'error';

        header('Location: ' . BASE_URL . '/superadmin/settings?tab=templates');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $ok = $emailTemplate->deleteTemplate($id);
            $_SESSION['flash']      = $ok ? 'Template verwijderd!' : 'Fout bij verwijderen.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        }

        header('Location: ' . BASE_URL . '/superadmin/settings?tab=templates');
        exit;
    }
}

// Read flash message from session (set by PRG redirect)
$flash            = $_SESSION['flash'] ?? null;
$templateFlashType = $_SESSION['flash_type'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_type']);

// Fetch ALL global templates (all languages)
$templates = $emailTemplate->getTemplatesByTenant(null);

// Determine active tab
$activeTab = $_GET['tab'] ?? 'platform';
if (isset($_POST['platform_settings_action'])) $activeTab = 'platform';
if (isset($_POST['email_settings_action']))    $activeTab = 'email';
if (isset($_POST['test_email_action']))        $activeTab = 'email';

// Prepare display values (mask secrets)
$modeValue     = $settings['mollie_mode_default'] ?? 'mock';
$clientIdValue = $settings['mollie_connect_client_id'] ?? '';
$hasSecret     = !empty($settings['mollie_connect_client_secret']);
$hasApiKey     = !empty($settings['mollie_connect_api_key']);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>Platform Instellingen</h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary" style="width: auto;">&larr; Terug</a>
    </div>

    <!-- Tab Navigation -->
    <div class="settings-tabs" style="margin-bottom: var(--space-lg); border-bottom: 1px solid rgba(255,255,255,0.1);">
        <button class="tab-button" data-tab="platform"
                style="padding: var(--space-sm) var(--space-md);
                       background: <?= $activeTab === 'platform' ? 'var(--accent-primary)' : 'transparent' ?>;
                       border: none;
                       color: <?= $activeTab === 'platform' ? '#fff' : 'var(--text-secondary)' ?>;
                       cursor: pointer;
                       border-radius: var(--radius-sm) var(--radius-sm) 0 0;"
                onclick="switchTab('platform')">Platform Instellingen</button>
        <button class="tab-button" data-tab="email"
                style="padding: var(--space-sm) var(--space-md);
                       background: <?= $activeTab === 'email' ? 'var(--accent-primary)' : 'transparent' ?>;
                       border: none;
                       color: <?= $activeTab === 'email' ? '#fff' : 'var(--text-secondary)' ?>;
                       cursor: pointer;
                       border-radius: var(--radius-sm) var(--radius-sm) 0 0;"
                onclick="switchTab('email')">Email Instellingen</button>
        <button class="tab-button" data-tab="templates"
                style="padding: var(--space-sm) var(--space-md);
                       background: <?= $activeTab === 'templates' ? 'var(--accent-primary)' : 'transparent' ?>;
                       border: none;
                       color: <?= $activeTab === 'templates' ? '#fff' : 'var(--text-secondary)' ?>;
                       cursor: pointer;
                       border-radius: var(--radius-sm) var(--radius-sm) 0 0;"
                onclick="switchTab('templates')">Email Templates</button>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">

        <!-- ==================== PLATFORM SETTINGS TAB ==================== -->
        <div id="platform-tab" class="tab-pane" style="display: <?= $activeTab === 'platform' ? 'block' : 'none' ?>;">

            <?php if ($platformSettingsSaved): ?>
            <div style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
                background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);">
                Instellingen opgeslagen!
            </div>
            <?php endif; ?>

            <!-- Mollie Connect Configuration -->
            <form class="glass-card" method="POST" action="" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
                <input type="hidden" name="platform_settings_action" value="1">

                <h2 style="margin-bottom: var(--space-sm); color: var(--accent-primary);">Mollie Connect</h2>
                <p class="text-secondary text-sm" style="margin-bottom: var(--space-lg);">
                    Configureer de Mollie Connect OAuth credentials. Deze worden gebruikt om tenants te koppelen via Mollie Connect (Marketplace).
                    Vraag deze aan via het <a href="https://www.mollie.com/dashboard/developers/oauth-apps" target="_blank" rel="noopener" style="color: var(--accent-primary);">Mollie Dashboard &rarr; OAuth Apps</a>.
                </p>

                <div class="form-group">
                    <label>Mollie Modus (standaard)</label>
                    <select name="mollie_mode_default" class="form-input">
                        <option value="mock" <?= $modeValue === 'mock' ? 'selected' : '' ?>>Mock (simulatie, geen echte API calls)</option>
                        <option value="test" <?= $modeValue === 'test' ? 'selected' : '' ?>>Test (Mollie test keys)</option>
                        <option value="live" <?= $modeValue === 'live' ? 'selected' : '' ?>>Live (productie)</option>
                    </select>
                    <p class="text-sm text-secondary" style="margin-top: 4px;">Bepaalt de standaardmodus voor nieuwe betalingen.</p>
                </div>

                <div class="form-group">
                    <label>OAuth Client ID</label>
                    <input type="text" name="mollie_connect_client_id" class="form-input"
                           value="<?= sanitize($clientIdValue) ?>"
                           placeholder="app_xxxxxxxxxxxxxxxx">
                    <p class="text-sm text-secondary" style="margin-top: 4px;">De Client ID van je Mollie OAuth applicatie.</p>
                </div>

                <div class="form-group">
                    <label>OAuth Client Secret <?= $hasSecret ? '(leeg = huidige behouden)' : '*' ?></label>
                    <input type="password" name="mollie_connect_client_secret" class="form-input"
                           placeholder="<?= $hasSecret ? '•••••••• (huidige behouden)' : 'Voer Client Secret in' ?>">
                    <p class="text-sm text-secondary" style="margin-top: 4px;">Het Client Secret van je Mollie OAuth applicatie.</p>
                </div>

                <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: var(--space-lg) 0;">

                <div class="form-group">
                    <label>Platform API Key <?= $hasApiKey ? '(leeg = huidige behouden)' : '*' ?></label>
                    <input type="password" name="mollie_connect_api_key" class="form-input"
                           placeholder="<?= $hasApiKey ? '•••••••• (huidige behouden)' : 'live_xxxxxxxxxxxxxxxxxxxxxxxx' ?>">
                    <p class="text-sm text-secondary" style="margin-top: 4px;">
                        Het platform API key (live). Wordt gebruikt voor OAuth code exchange en Connect beheer.
                    </p>
                </div>

                <div style="text-align: center; margin-top: var(--space-lg);">
                    <button type="submit" class="btn btn-primary" style="width: auto; min-width: 200px;">Instellingen Opslaan</button>
                </div>
            </form>

            <!-- Status Overview -->
            <div class="glass-card" style="padding: var(--space-lg);">
                <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Verbindingsstatus</h2>
                <div style="display: grid; gap: var(--space-sm);">
                    <?php
                    $statusItems = [
                        'Modus'            => strtoupper($modeValue),
                        'Client ID'        => !empty($clientIdValue) ? '<span style="color:#4CAF50;">&#10003; Ingesteld</span>' : '<span style="color:#f44336;">&#10007; Niet ingesteld</span>',
                        'Client Secret'    => $hasSecret ? '<span style="color:#4CAF50;">&#10003; Ingesteld</span>' : '<span style="color:#f44336;">&#10007; Niet ingesteld</span>',
                        'Platform API Key' => $hasApiKey ? '<span style="color:#4CAF50;">&#10003; Ingesteld</span>' : '<span style="color:#f44336;">&#10007; Niet ingesteld</span>',
                    ];
                    foreach ($statusItems as $label => $value): ?>
                    <div style="display: flex; justify-content: space-between; padding: var(--space-xs) 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span class="text-secondary"><?= $label ?></span>
                        <span><?= $value ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                $allConfigured = !empty($clientIdValue) && $hasSecret && $hasApiKey;
                if ($allConfigured): ?>
                <p style="margin-top: var(--space-md); color: #4CAF50; font-weight: 600;">
                    &#10003; Mollie Connect is volledig geconfigureerd. Je kunt nu tenants koppelen.
                </p>
                <?php else: ?>
                <p style="margin-top: var(--space-md); color: #FF9800; font-weight: 600;">
                    &#9888; Vul alle velden in om Mollie Connect te activeren.
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==================== EMAIL SETTINGS TAB ==================== -->
        <div id="email-tab" class="tab-pane" style="display: <?= $activeTab === 'email' ? 'block' : 'none' ?>;">

            <?php if ($emailSettingsSaved): ?>
            <div style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
                background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);">
                Email configuratie opgeslagen!
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
                <input type="hidden" name="email_settings_action" value="1">

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
                               value="<?= sanitize($config['from_name'] ?? 'REGULR.vip') ?>">
                    </div>
                </div>

                <div style="text-align: center; margin-top: var(--space-lg);">
                    <button type="submit" class="btn btn-primary" style="width: auto; min-width: 200px;">Configuratie Opslaan</button>
                </div>
            </form>

            <!-- Test Email -->
            <form class="glass-card" method="POST" action="" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
                <input type="hidden" name="test_email_action" value="1">
                <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Test Email Versturen</h2>
                <?php if ($config): ?>
                <div style="display: flex; gap: var(--space-md); align-items: flex-end; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                        <label>Ontvanger e-mailadres</label>
                        <input type="email" name="test_email" class="form-input"
                               placeholder="jouw@email.nl" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: auto; white-space: nowrap;">Verstuur Test</button>
                </div>
                <?php else: ?>
                <p class="text-secondary">Sla eerst een SMTP configuratie op voordat je een test email kunt versturen.</p>
                <?php endif; ?>
            </form>
        </div>

        <!-- ==================== EMAIL TEMPLATES TAB ==================== -->
        <div id="templates-tab" class="tab-pane" style="display: <?= $activeTab === 'templates' ? 'block' : 'none' ?>;">

            <?php if ($flash): ?>
            <div style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
                <?= $templateFlashType === 'success' ? 'background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);' : 'background: rgba(244,67,54,0.15); color: #f44336; border: 1px solid rgba(244,67,54,0.3);' ?>">
                <?= sanitize($flash) ?>
            </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="glass-card" style="padding: var(--space-md); margin-bottom: var(--space-lg); border-left: 4px solid var(--accent-primary);">
                <p class="text-sm text-secondary">
                    <strong>Superadmin</strong> beheert alle globale (default) email templates voor het platform. Tenants kunnen deze templates overschrijven met hun eigen versies.
                </p>
            </div>

            <!-- Templates List -->
            <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
                <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Alle Email Templates</h2>

                <?php if (empty($templates)): ?>
                <p class="text-secondary">Geen templates gevonden.</p>
                <?php else: ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Type</th>
                        <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Onderwerp</th>
                        <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Taal</th>
                        <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Bijgewerkt</th>
                        <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Acties</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $tpl): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: var(--space-sm);"><span class="badge"><?= sanitize($tpl['type']) ?></span></td>
                        <td style="padding: var(--space-sm);"><?= sanitize($tpl['subject']) ?></td>
                        <td style="padding: var(--space-sm);"><?= sanitize($tpl['language_code']) ?></td>
                        <td style="padding: var(--space-sm); font-size: 13px; color: var(--text-secondary);"><?= $tpl['updated_at'] ?></td>
                        <td style="padding: var(--space-sm); text-align: right;">
                            <button type="button" class="btn btn-secondary" style="width: auto; padding: 6px 12px; font-size: 13px;"
                                onclick="editTemplate(<?= (int)$tpl['id'] ?>, '<?= addslashes($tpl['type']) ?>', '<?= addslashes($tpl['subject']) ?>', '<?= addslashes($tpl['language_code']) ?>')">
                                Bewerken
                            </button>
                            <form method="POST" action="" style="display: inline-block;">
                                <input type="hidden" name="email_template_action" value="1">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="width: auto; padding: 6px 12px; font-size: 13px;"
                                    onclick="return confirm('Weet je zeker dat je deze template wilt verwijderen?')">Verwijderen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <div style="margin-top: var(--space-md);">
                    <button type="button" class="btn btn-primary" style="width: auto;" onclick="newTemplate()">
                        + Nieuwe Template
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Template Editor Modal -->
<div id="template-modal" style="display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.7); backdrop-filter: blur(8px); overflow-y: auto;">
    <div style="max-width: 900px; margin: 2rem auto; background: #1a1a2e; border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.1);">
        <div style="padding: var(--space-lg); border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center;">
            <h2 id="modal-title">Template Bewerken</h2>
            <button type="button" onclick="closeModal()" style="background: none; border: none; color: var(--text-secondary); font-size: 24px; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="" style="padding: var(--space-lg);">
            <input type="hidden" name="email_template_action" value="1">
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" id="modal-id">

            <div class="form-group">
                <label>Template Type *</label>
                <select name="type" id="modal-type" class="form-input" required>
                    <option value="tenant_welcome">Tenant Welcome</option>
                    <option value="admin_invite">Admin Invite</option>
                    <option value="guest_confirmation">Guest Confirmation</option>
                    <option value="guest_password_reset">Guest Password Reset</option>
                    <option value="marketing">Marketing</option>
                </select>
            </div>

            <div class="form-group">
                <label>Onderwerp *</label>
                <input type="text" name="subject" id="modal-subject" class="form-input" required placeholder="Welkom bij REGULR.vip">
            </div>

            <div class="form-group">
                <label>Taal</label>
                <select name="language_code" id="modal-language" class="form-input">
                    <option value="nl">Nederlands</option>
                    <option value="en">English</option>
                    <option value="fr">Français</option>
                    <option value="de">Deutsch</option>
                </select>
            </div>

            <div class="form-group">
                <label>HTML Content *</label>
                <textarea name="content" id="modal-content" class="form-input" rows="18" required
                    placeholder="HTML email template met {{placeholders}}"></textarea>
                <small class="text-sm text-secondary">Beschikbare placeholders: {{tenant_name}}, {{password_reset_link}}</small>
            </div>

            <div class="form-group">
                <label>Plain Text Content</label>
                <textarea name="text_content" id="modal-text-content" class="form-input" rows="4"
                    placeholder="Tekstversie voor email clients zonder HTML"></textarea>
            </div>

            <div style="display: flex; gap: var(--space-md); justify-content: flex-end; margin-top: var(--space-lg);">
                <button type="button" onclick="closeModal()" class="btn btn-secondary" style="width: auto;">Annuleren</button>
                <button type="submit" class="btn btn-primary" style="width: auto;">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tabName) {
    var url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);

    document.querySelectorAll('.tab-button').forEach(function(button) {
        button.style.background = 'transparent';
        button.style.color = 'var(--text-secondary)';
        if (button.dataset.tab === tabName) {
            button.style.background = 'var(--accent-primary)';
            button.style.color = '#fff';
        }
    });

    document.querySelectorAll('.tab-pane').forEach(function(pane) {
        pane.style.display = 'none';
    });

    document.getElementById(tabName + '-tab').style.display = 'block';
}

function newTemplate() {
    document.getElementById('modal-title').textContent = 'Nieuwe Template';
    document.getElementById('modal-id').value = '';
    document.getElementById('modal-type').value = 'tenant_welcome';
    document.getElementById('modal-type').disabled = false;
    document.getElementById('modal-subject').value = '';
    document.getElementById('modal-content').value = '';
    document.getElementById('modal-text-content').value = '';
    document.getElementById('modal-language').value = 'nl';
    document.getElementById('template-modal').style.display = 'block';
}

function editTemplate(id, type, subject, language) {
    document.getElementById('modal-title').textContent = 'Template Bewerken';
    document.getElementById('modal-id').value = id;
    document.getElementById('modal-type').value = type;
    document.getElementById('modal-type').disabled = true;
    document.getElementById('modal-subject').value = subject;
    document.getElementById('modal-language').value = language;

    var placeholderHint = document.querySelector('#modal-content + small');
    if (placeholderHint) {
        var hints = {
            'tenant_welcome': '{{tenant_name}}, {{password_reset_link}}',
            'admin_invite': '{{user_name}}, {{tenant_name}}, {{invitation_link}}',
            'guest_confirmation': '{{guest_name}}, {{tenant_name}}, {{verification_code}}, {{verification_link}}',
            'guest_password_reset': '{{guest_name}}, {{tenant_name}}, {{password_reset_link}}',
            'marketing': '{{tenant_name}}, {{campaign_name}}, {{campaign_message}}, {{action_url}}, {{action_text}}, {{unsubscribe_url}}'
        };
        placeholderHint.textContent = 'Beschikbare placeholders: ' + (hints[type] || '{{tenant_name}}');
    }

    fetch('<?= BASE_URL ?>/api/email/templates?id=' + id + '&_=' + Date.now())
        .then(function(r) {
            if (!r.ok) {
                if (r.status === 404) {
                    throw new Error('Template niet gevonden (ID: ' + id + '). Vernieuw de pagina.');
                }
                throw new Error('API error: ' + r.status);
            }
            return r.json();
        })
        .then(function(data) {
            var template = Array.isArray(data) ? data[0] : data;
            if (template && template.content) {
                document.getElementById('modal-content').value = template.content;
                document.getElementById('modal-text-content').value = template.text_content || '';
            } else {
                alert('Fout: Geen content gevonden in template.');
            }
        })
        .catch(function(err) {
            alert('Fout bij laden template: ' + err.message);
            closeModal();
        });

    document.getElementById('template-modal').style.display = 'block';
}

function closeModal() {
    document.getElementById('template-modal').style.display = 'none';
}

document.getElementById('template-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
