<?php
declare(strict_types=1);
/**
 * Superadmin - Tenant Detail View
 * STAMGAST Loyalty Platform
 * Shows: NAW info (editable), stats, users with role management
 */

require_once __DIR__ . '/../../models/Tenant.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);

// Extract tenant ID from URL: /superadmin/tenant/{id}
$route = trim($_GET['route'] ?? '', '/');
$parts = explode('/', $route);
$tenantId = (int) ($parts[2] ?? 0);

if ($tenantId <= 0) {
    http_response_code(404);
    require VIEWS_PATH . 'shared/header.php';
    echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Tenant niet gevonden</p><a href="<?= BASE_URL ?>/superadmin" class="btn btn-primary">Terug</a></div>';
    require VIEWS_PATH . 'shared/footer.php';
    exit;
}

$tenant = $tenantModel->findById($tenantId);
if (!$tenant) {
    http_response_code(404);
    require VIEWS_PATH . 'shared/header.php';
    echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Tenant niet gevonden</p><a href="<?= BASE_URL ?>/superadmin" class="btn btn-primary">Terug</a></div>';
    require VIEWS_PATH . 'shared/footer.php';
    exit;
}

$stats = $tenantModel->getTenantStats($tenantId);
$users = $tenantModel->getUsersWithWallets($tenantId);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 1100px; margin: 0 auto;">
    <!-- Navigation -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1><?= sanitize($tenant['name']) ?></h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Omzet</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['revenue'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Stortingen</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['deposits'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">In Wallets</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['total_balance'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Punten</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['total_points'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Gebruikers</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);"><?= $stats['total_users'] ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Vandaag</p>
            <p style="font-size: 24px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($stats['today_revenue'] / 100, 2, ',', '.') ?></p>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-lg);">
        <!-- Left: NAW Details -->
        <div>
            <div class="glass-card" style="padding: var(--space-lg);">
                <h2 style="margin-bottom: var(--space-md);">NAW Gegevens</h2>
                <form id="naw-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group">
                        <label class="text-sm text-secondary">Naam</label>
                        <input type="text" id="naw-name" class="form-input" value="<?= sanitize($tenant['name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Slug</label>
                        <input type="text" id="naw-slug" class="form-input" value="<?= sanitize($tenant['slug']) ?>" placeholder="bijv. cafe-de-luifel">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Contactpersoon</label>
                        <input type="text" id="naw-contact_name" class="form-input" value="<?= sanitize($tenant['contact_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Contact E-mail</label>
                        <input type="email" id="naw-contact_email" class="form-input" value="<?= sanitize($tenant['contact_email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Telefoon</label>
                        <input type="text" id="naw-phone" class="form-input" value="<?= sanitize($tenant['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Adres</label>
                        <input type="text" id="naw-address" class="form-input" value="<?= sanitize($tenant['address'] ?? '') ?>">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-sm);">
                        <div class="form-group">
                            <label class="text-sm text-secondary">Postcode</label>
                            <input type="text" id="naw-postal_code" class="form-input" value="<?= sanitize($tenant['postal_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Plaats</label>
                            <input type="text" id="naw-city" class="form-input" value="<?= sanitize($tenant['city'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Land</label>
                        <input type="text" id="naw-country" class="form-input" value="<?= sanitize($tenant['country'] ?? 'Nederland') ?>">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Mollie Status</label>
                        <select id="naw-mollie_status" class="form-input">
                            <option value="mock" <?= ($tenant['mollie_status'] ?? '') === 'mock' ? 'selected' : '' ?>>Mock</option>
                            <option value="test" <?= ($tenant['mollie_status'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                            <option value="live" <?= ($tenant['mollie_status'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
                        </select>
                    </div>

                    <!-- Tenant Status Toggle -->
                    <div style="border-top: 1px solid var(--glass-border); padding-top: var(--space-md); margin-top: var(--space-md); margin-bottom: var(--space-md);">
                        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm); color: <?= ($tenant['is_active'] ?? true) ? 'var(--accent-primary)' : '#f44336' ?>;">Tenant Status</h3>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:var(--space-sm);cursor:pointer;">
                                <input type="checkbox" id="naw-is_active" <?= ($tenant['is_active'] ?? true) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span id="naw-is_active-label" style="color: <?= ($tenant['is_active'] ?? true) ? '#4CAF50' : '#f44336' ?>;">
                                    <?= ($tenant['is_active'] ?? true) ? 'Actief' : 'Uitgeschakeld' ?>
                                </span>
                            </label>
                            <p class="text-sm text-secondary" style="margin-top:4px;">Schakel uit om de tenant en alle gebruikers tijdelijk te blokkeren.</p>
                        </div>
                    </div>

                    <!-- Module Toggles (Platform beheerder) -->
                    <div style="border-top: 1px solid var(--glass-border); padding-top: var(--space-md); margin-top: var(--space-md); margin-bottom: var(--space-md);">
                        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm); color: var(--accent-primary);">Modules</h3>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Push Notificaties</label>
                            <label style="display:flex;align-items:center;gap:var(--space-sm);cursor:pointer;">
                                <input type="checkbox" id="naw-feature_push" <?= ($tenant['feature_push'] ?? true) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span id="naw-feature_push-label"><?= ($tenant['feature_push'] ?? true) ? 'Actief' : 'Inactief' ?></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Marketing Studio</label>
                            <label style="display:flex;align-items:center;gap:var(--space-sm);cursor:pointer;">
                                <input type="checkbox" id="naw-feature_marketing" <?= ($tenant['feature_marketing'] ?? true) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span id="naw-feature_marketing-label"><?= ($tenant['feature_marketing'] ?? true) ? 'Actief' : 'Inactief' ?></span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:var(--space-sm);">Opslaan</button>
                    <p id="naw-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                </form>
            </div>
            
            <!-- Password Change Form -->
            <div class="glass-card" style="padding: var(--space-lg); margin-top: var(--space-md);">
                <h2 style="margin-bottom: var(--space-md);">Wachtwoord Wijzigen</h2>
                <form id="password-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group">
                        <label class="text-sm text-secondary">E-mail van Admin</label>
                        <input type="email" id="admin-email" class="form-input" placeholder="admin@voorbeeld.nl" required>
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Nieuw Wachtwoord</label>
                        <input type="password" id="new-password" class="form-input" placeholder="Nieuw wachtwoord" required>
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Bevestig Nieuw Wachtwoord</label>
                        <input type="password" id="confirm-password" class="form-input" placeholder="Bevestig nieuw wachtwoord" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:var(--space-sm);">Wachtwoord Wijzigen</button>
                    <p id="password-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                </form>
</div>
        </div>

<!-- Right: Users List -->
        <div>
            <div class="glass-card" style="padding: var(--space-lg);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
                    <h2>
                        Gebruikers (<?= count($users) ?>)
                        <span class="text-sm text-secondary" style="font-weight:400;">
                            &mdash;
                            <?= ($stats['user_counts']['admin'] ?? 0) ?> admin,
                            <?= ($stats['user_counts']['bartender'] ?? 0) ?> bartenders,
                            <?= ($stats['user_counts']['guest'] ?? 0) ?> gasten
                        </span>
                    </h2>
                    <button id="change-password-btn" class="btn btn-primary" style="display: none;">Wachtwoord Wijzigen</button>
                </div>

                <?php if (empty($users)): ?>
                    <p class="text-secondary">Geen gebruikers gevonden.</p>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Naam</th>
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">E-mail</th>
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Saldo</th>
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Rol</th>
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Laatst actief</th>
                                <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);" data-user-id="<?= (int) $u['id'] ?>">
                                <td style="padding: var(--space-sm);">
                                    <?= sanitize($u['first_name'] . ' ' . $u['last_name']) ?>
                                </td>
                                <td style="padding: var(--space-sm); font-size: 13px;">
                                    <?= sanitize($u['email']) ?>
                                </td>
                                <td style="padding: var(--space-sm);">
                                    &euro; <?= number_format(((int) ($u['balance_cents'] ?? 0)) / 100, 2, ',', '.') ?>
                                </td>
                                <td style="padding: var(--space-sm);">
                                    <?php $role = $u['role'] ?? 'guest'; ?>
                                    <?php if ($role === 'superadmin'): ?>
                                        <span class="badge" style="background:var(--accent-primary);color:#000;">Superadmin</span>
                                    <?php else: ?>
                                        <select class="form-input role-select" data-user-id="<?= (int) $u['id'] ?>" style="padding:4px 8px;font-size:13px;min-width:110px;">
                                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            <option value="bartender" <?= $role === 'bartender' ? 'selected' : '' ?>>Bartender</option>
                                            <option value="guest" <?= $role === 'guest' ? 'selected' : '' ?>>Gast</option>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: var(--space-sm); font-size: 13px; color: var(--text-secondary);">
                                    <?= $u['last_activity'] ? date('d-m-Y H:i', strtotime($u['last_activity'])) : '-' ?>
                                </td>
                                <td style="padding: var(--space-sm);">
                                    <button class="btn btn-secondary btn-sm select-user-btn" data-user-id="<?= (int) $u['id'] ?>" data-user-email="<?= sanitize($u['email']) ?>" style="padding: 2px 8px; font-size: 12px;">Bewerk</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <!-- Password Change Form (Hidden by default) -->
                <div id="user-password-change-form" class="glass-card" style="padding: var(--space-lg); margin-top: var(--space-md); display: none;">
                    <h3 style="margin-bottom: var(--space-md);">Wachtwoord Wijzigen</h3>
                    <form id="user-password-form">
                        <input type="hidden" id="selected-user-id" name="user_id" value="">
                        <div class="form-group">
                            <label class="text-sm text-secondary">Gebruiker</label>
                            <input type="text" id="selected-user-email" class="form-input" readonly>
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Nieuw Wachtwoord</label>
                            <input type="password" id="user-new-password" class="form-input" placeholder="Nieuw wachtwoord" required>
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Bevestig Nieuw Wachtwoord</label>
                            <input type="password" id="user-confirm-password" class="form-input" placeholder="Bevestig nieuw wachtwoord" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:var(--space-sm);">Wachtwoord Wijzigen</button>
                        <button type="button" id="cancel-password-change" class="btn btn-secondary" style="width:100%;margin-top:var(--space-sm);">Annuleren</button>
                        <p id="user-password-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= generateCSRFToken() ?>';
const TENANT_ID = <?= $tenantId ?>;

// User selection and password change functionality
document.querySelectorAll('.select-user-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const userId = this.dataset.userId;
        const userEmail = this.dataset.userEmail;
        
        // Show the password change form
        document.getElementById('user-password-change-form').style.display = 'block';
        document.getElementById('selected-user-id').value = userId;
        document.getElementById('selected-user-email').value = userEmail;
        
        // Scroll to the form
        document.getElementById('user-password-change-form').scrollIntoView({ behavior: 'smooth' });
    });
});

// Cancel password change
document.getElementById('cancel-password-change')?.addEventListener('click', function() {
    document.getElementById('user-password-change-form').style.display = 'none';
    document.getElementById('selected-user-id').value = '';
    document.getElementById('selected-user-email').value = '';
    document.getElementById('user-new-password').value = '';
    document.getElementById('user-confirm-password').value = '';
    document.getElementById('user-password-status').textContent = '';
});

// Password change form handler
document.getElementById('user-password-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const statusEl = document.getElementById('user-password-status');
    const userId = document.getElementById('selected-user-id').value;
    const userEmail = document.getElementById('selected-user-email').value;
    const newPassword = document.getElementById('user-new-password').value;
    const confirmPassword = document.getElementById('user-confirm-password').value;
    
    statusEl.textContent = 'Wachtwoord wijzigen...';
    statusEl.style.color = 'var(--text-secondary)';
    
    // Validate password match
    if (newPassword !== confirmPassword) {
        statusEl.textContent = 'Wachtwoorden komen niet overeen';
        status2El.style.color = '#f44336';
        return;
    }
    
    // Validate password strength (at least 8 characters)
    if (newPassword.length < 8) {
        statusEl.textContent = 'Wachtwoord moet minimaal 8 tekens bevatten';
        statusEl.style.color = '#f44336';
        return;
    }
    
    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ 
                action: 'change_password', 
                tenant_id: parseInt(TENANT_ID), 
                user_id: parseInt(userId),
                new_password: newPassword 
            })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ Wachtwoord gewijzigd';
            statusEl.style.color = '#4CAF50';
            // Clear form fields
            document.getElementById('user-new-password').value = '';
            document.getElementById('user-confirm-password').value = '';
        } else {
            statusEl.textContent = '✗ ' + (result.error || 'Fout bij wijzigen wachtwoord');
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 3000);
});

// NAW form save
document.getElementById('naw-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const statusEl = document.getElementById('naw-status');
    statusEl.textContent = 'Opslaan...';
    statusEl.style.color = 'var(--text-secondary)';

    const fields = ['name','slug','contact_name','contact_email','phone','address','postal_code','city','country','mollie_status'];
    const data = { action: 'update', tenant_id: TENANT_ID };
    fields.forEach(f => {
        const el = document.getElementById('naw-' + f);
        if (el) data[f] = el.value.trim();
    });

    // Include feature toggles and tenant status
    const pushEl = document.getElementById('naw-feature_push');
    const mktEl = document.getElementById('naw-feature_marketing');
    const activeEl = document.getElementById('naw-is_active');
    if (pushEl) data.feature_push = pushEl.checked ? 1 : 0;
    if (mktEl) data.feature_marketing = mktEl.checked ? 1 : 0;
    if (activeEl) data.is_active = activeEl.checked ? 1 : 0;

    try {
        const res = await fetch('/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ Opgeslagen';
            statusEl.style.color = '#4CAF50';
        } else {
            statusEl.textContent = '✗ ' + (result.error || 'Fout');
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 3000);
});

// Feature toggle label updates
document.getElementById('naw-feature_push')?.addEventListener('change', function() {
    document.getElementById('naw-feature_push-label').textContent = this.checked ? 'Actief' : 'Inactief';
});
document.getElementById('naw-feature_marketing')?.addEventListener('change', function() {
    document.getElementById('naw-feature_marketing-label').textContent = this.checked ? 'Actief' : 'Inactief';
});
// Tenant status toggle label update
document.getElementById('naw-is_active')?.addEventListener('change', function() {
    const label = document.getElementById('naw-is_active-label');
    label.textContent = this.checked ? 'Actief' : 'Uitgeschakeld';
    label.style.color = this.checked ? '#4CAF50' : '#f44336';
});

// Role change
document.querySelectorAll('.role-select').forEach(sel => {
    sel.addEventListener('change', async function() {
        const userId = this.dataset.userId;
        const newRole = this.value;
        const originalBg = this.style.background;

        this.style.background = 'rgba(255,193,7,0.3)';
        try {
            const res = await fetch('/api/superadmin/tenants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'update_role', user_id: parseInt(userId), role: newRole })
            });
            const result = await res.json();
            if (result.success) {
                this.style.background = 'rgba(76,175,80,0.3)';
                setTimeout(() => { this.style.background = originalBg || ''; }, 1500);
            } else {
                alert('Fout: ' + (result.error || 'Onbekend'));
                this.style.background = originalBg || '';
            }
        } catch (err) {
            alert('Netwerkfout: ' + err.message);
            this.style.background = originalBg || '';
        }
    });
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
