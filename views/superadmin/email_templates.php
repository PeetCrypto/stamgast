<?php
declare(strict_types=1);
/**
 * Superadmin Email Templates Management
 * Manage all global (default) email templates for the platform
 */

require_once __DIR__ . '/../../models/EmailTemplate.php';

$db = Database::getInstance()->getConnection();
$emailTemplate = new EmailTemplate($db);

// Handle form actions (POST-Redirect-GET pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $data = [
            'type'           => $_POST['type'] ?? 'tenant_welcome',
            'subject'        => trim($_POST['subject'] ?? ''),
            'content'        => $_POST['content'] ?? '',
            'text_content'   => trim($_POST['text_content'] ?? ''),
            'language_code'  => $_POST['language_code'] ?? 'nl',
            'is_default'     => 1,
            'tenant_id'      => null, // Global template (superadmin)
        ];
        $id = $_POST['id'] ?? '';
        if (!empty($id)) {
            $data['id'] = (int)$id;
        }

        $ok = $emailTemplate->saveTemplate($data);
        $_SESSION['flash']        = $ok ? 'Template opgeslagen!' : 'Fout bij opslaan template.';
        $_SESSION['flash_type']   = $ok ? 'success' : 'error';

        header('Location: ' . BASE_URL . '/superadmin/email-templates');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $ok = $emailTemplate->deleteTemplate($id);
            $_SESSION['flash']      = $ok ? 'Template verwijderd!' : 'Fout bij verwijderen.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';
        }

        header('Location: ' . BASE_URL . '/superadmin/email-templates');
        exit;
    }
}

// Read flash message from session (set by PRG redirect)
$flash     = $_SESSION['flash'] ?? null;
$flashType = $_SESSION['flash_type'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_type']);

// Fetch ALL global templates (all languages) — no language filter
$templates = $emailTemplate->getTemplatesByTenant(null);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 900px; margin: 0 auto;">
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
    <h1>Email Templates</h1>
    <div style="display: flex; gap: var(--space-sm);">
        <a href="<?= BASE_URL ?>/superadmin/email-settings" class="btn btn-secondary" style="width: auto;">Email Instellingen</a>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary" style="width: auto;">&larr; Terug</a>
    </div>
</div>

<?php if ($flash): ?>
<div style="margin-bottom: var(--space-md); padding: var(--space-md); border-radius: var(--radius-md);
    <?= $flashType === 'success' ? 'background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3);' : 'background: rgba(244,67,54,0.15); color: #f44336; border: 1px solid rgba(244,67,54,0.3);' ?>">
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

<!-- Template Editor Modal -->
<div id="template-modal" style="display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.7); backdrop-filter: blur(8px); overflow-y: auto;">
    <div style="max-width: 900px; margin: 2rem auto; background: #1a1a2e; border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.1);">
        <div style="padding: var(--space-lg); border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center;">
            <h2 id="modal-title">Template Bewerken</h2>
            <button type="button" onclick="closeModal()" style="background: none; border: none; color: var(--text-secondary); font-size: 24px; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="" style="padding: var(--space-lg);">
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
function newTemplate() {
    document.getElementById('modal-title').textContent = 'Nieuwe Template';
    document.getElementById('modal-id').value = '';
    document.getElementById('modal-type').value = 'tenant_welcome';
    document.getElementById('modal-subject').value = '';
    document.getElementById('modal-content').value = '';
    document.getElementById('modal-text-content').value = '';
    document.getElementById('modal-language').value = 'nl';
    document.getElementById('template-modal').style.display = 'block';

    // Enable type selector for new template
    document.getElementById('modal-type').disabled = false;
}

function editTemplate(id, type, subject, language) {
    document.getElementById('modal-title').textContent = 'Template Bewerken';
    document.getElementById('modal-id').value = id;
    document.getElementById('modal-type').value = type;
    document.getElementById('modal-subject').value = subject;
    document.getElementById('modal-language').value = language;

    // Disable type selector when editing existing template
    document.getElementById('modal-type').disabled = true;

    // Update placeholder hint based on template type
    const placeholderHint = document.querySelector('#modal-content + small');
    if (placeholderHint) {
        const hints = {
            'tenant_welcome': '{{tenant_name}}, {{password_reset_link}}',
            'admin_invite': '{{user_name}}, {{tenant_name}}, {{invitation_link}}',
            'guest_confirmation': '{{guest_name}}, {{tenant_name}}, {{verification_code}}, {{verification_link}}',
            'guest_password_reset': '{{guest_name}}, {{tenant_name}}, {{password_reset_link}}',
            'marketing': '{{tenant_name}}, {{campaign_name}}, {{campaign_message}}, {{action_url}}, {{action_text}}, {{unsubscribe_url}}'
        };
        placeholderHint.textContent = 'Beschikbare placeholders: ' + (hints[type] || '{{tenant_name}}');
    }

    // Fetch full template content via API
    fetch('<?= BASE_URL ?>/api/email/templates?id=' + id + '&_=' + Date.now()) // Cache buster
        .then(r => {
            if (!r.ok) {
                if (r.status === 404) {
                    throw new Error('Template niet gevonden (ID: ' + id + '). Vernieuw de pagina om de actuele lijst te laden.');
                }
                throw new Error('API error: ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            // Handle both single object and array responses
            const template = Array.isArray(data) ? data[0] : data;
            if (template && template.content) {
                document.getElementById('modal-content').value = template.content;
                document.getElementById('modal-text-content').value = template.text_content || '';
            } else {
                console.error('No content in template:', template);
                alert('Fout: Geen content gevonden in template. Probeer de pagina te vernieuwen.');
            }
        })
        .catch(err => {
            console.error('Failed to load template:', err);
            alert('Fout bij laden template: ' + err.message);
            closeModal();
        });

    document.getElementById('template-modal').style.display = 'block';
}

function closeModal() {
    document.getElementById('template-modal').style.display = 'none';
}

// Close modal on backdrop click
document.getElementById('template-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
