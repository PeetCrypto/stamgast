<?php
declare(strict_types=1);
/**
 * Superadmin - Tenant Management
 * REGULR.vip Loyalty Platform
 */

require_once __DIR__ . '/../../models/Tenant.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);

// Handle POST: create new tenant
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    if (!empty($input)) {
        $name = trim($input['name'] ?? '');
        $slug = trim($input['slug'] ?? '');

        if (empty($name) || empty($slug)) {
            Response::error('Naam en slug zijn verplicht', 'MISSING_FIELDS', 400);
        }

        // Validate NAW fields — contact_email is REQUIRED
        if (empty($input['contact_email'])) {
            Response::error('Contact e-mailadres is verplicht', 'MISSING_EMAIL', 400);
        }
        if (!filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Ongeldig contact e-mailadres', 'INVALID_EMAIL', 400);
        }

        try {
            $id = $tenantModel->create([
                'name' => $name,
                'slug' => $slug,
                'brand_color' => $input['brand_color'] ?? '#FFC107',
                'secondary_color' => $input['secondary_color'] ?? '#FF9800',
                'contact_name' => $input['contact_name'] ?? null,
                'contact_email' => $input['contact_email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'address' => $input['address'] ?? null,
                'postal_code' => $input['postal_code'] ?? null,
                'city' => $input['city'] ?? null,
                'country' => $input['country'] ?? 'Nederland',
            ]);
            Response::success(['tenant_id' => $id], 201);
        } catch (\Throwable $e) {
            Response::error('Aanmaken mislukt: ' . $e->getMessage(), 'CREATE_FAILED', 500);
        }
    }
}

$tenants = $tenantModel->getAllWithUserCount();
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 1100px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>Tenant Beheer</h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Create Tenant Form (Hidden by default) -->
    <div id="create-tenant-modal" class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl); display: none;">
        <h2 style="margin-bottom: var(--space-md);">Nieuwe Tenant</h2>
        <form id="create-tenant-form" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="form-group">
                <label for="name">Naam</label>
                <input type="text" id="name" class="form-input" placeholder="Bijv. Cafe De Luifel" required>
            </div>
            <div class="form-group">
                <label for="slug">Slug (URL-vriendelijk)</label>
                <input type="text" id="slug" class="form-input" placeholder="bijv. cafe-de-luifel" required>
            </div>
            <div class="form-group">
                <label for="contact_name">Contactpersoon <span style="color:#f44336;">*</span></label>
                <input type="text" id="contact_name" class="form-input" placeholder="Bijv. Jan Jansen" required>
            </div>
            <div class="form-group">
                <label for="contact_email">Contact E-mail <span style="color:#f44336;">*</span></label>
                <input type="email" id="contact_email" class="form-input" placeholder="jan@jansen.nl" required>
            </div>
            <div class="form-group">
                <label for="phone">Telefoon</label>
                <input type="text" id="phone" class="form-input" placeholder="Bijv. +31 6 12345678">
            </div>
            <div class="form-group">
                <label for="address">Adres</label>
                <input type="text" id="address" class="form-input" placeholder="Bijv. Dorpsstraat 123">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-sm);">
                <div class="form-group">
                    <label for="postal_code">Postcode</label>
                    <input type="text" id="postal_code" class="form-input" placeholder="Bijv. 1234 AB">
                </div>
                <div class="form-group">
                    <label for="city">Plaats</label>
                    <input type="text" id="city" class="form-input" placeholder="Bijv. Amsterdam">
                </div>
            </div>
            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
                <button type="submit" class="btn btn-primary">Aanmaken</button>
                <button type="button" id="cancel-create" class="btn btn-secondary">Annuleren</button>
            </div>
        </form>
    </div>

    <!-- Tenants Table -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md); flex-wrap: wrap; gap: var(--space-sm);">
            <h2 style="margin: 0;">Bestaande Tenants (<span id="tenant-count"><?= count($tenants) ?></span>)</h2>
            <button id="show-create-form" class="btn btn-primary">+ Nieuwe Tenant</button>
        </div>
        
        <!-- Instant Search -->
        <div style="margin-bottom: var(--space-md);">
            <input type="text" 
                   id="tenant-search"
                   placeholder="Zoek op naam, slug, contact..."
                   autocomplete="off"
                   style="width: 100%; padding: 10px 14px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.15); background: rgba(0,0,0,0.2); color: white; font-size: 14px; box-sizing: border-box;">
        </div>
        
        <?php if (empty($tenants)): ?>
            <p class="text-secondary">Nog geen tenants.</p>
        <?php else: ?>
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Naam</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Status</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Contact</th>
                            <th style="text-align: center; padding: var(--space-sm); white-space: nowrap;">Gebruikers</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Slug</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Mollie</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Aangemaakt</th>
                            <th style="text-align: left; padding: var(--space-sm); white-space: nowrap;">Acties</th>
                        </tr>
                    </thead>
                    <tbody id="tenants-tbody">
                        <?php foreach ($tenants as $t): ?>
                        <?php $isActive = (bool) ($t['is_active'] ?? true); ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);" 
                            data-tenant-id="<?= (int) $t['id'] ?>"
                            data-search="<?= strtolower(htmlspecialchars(($t['name'] ?? '') . ' ' . ($t['slug'] ?? '') . ' ' . ($t['contact_name'] ?? '') . ' ' . ($t['contact_email'] ?? ''), ENT_QUOTES)) ?>">
                            <td style="padding: var(--space-sm); white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis;"><?= sanitize($t['name']) ?></td>
                            <td style="padding: var(--space-sm); white-space: nowrap;">
                                <span class="badge tenant-status-badge" data-tenant-id="<?= (int) $t['id'] ?>" style="background:<?= $isActive ? 'rgba(76,175,80,0.2)' : 'rgba(244,67,54,0.2)' ?>;color:<?= $isActive ? '#4CAF50' : '#f44336' ?>;cursor:default;">
                                    <?= $isActive ? 'Actief' : 'Inactief' ?>
                                </span>
                            </td>
                            <td style="padding: var(--space-sm); font-size: 13px; white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                <?= sanitize($t['contact_name'] ?? '') ?>
                                <?php if (!empty($t['contact_email'])): ?>
                                    <br><small style="opacity: 0.7;"><?= sanitize($t['contact_email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding: var(--space-sm); text-align: center; white-space: nowrap;">
                                <?= (int) ($t['user_count'] ?? 0) ?>
                            </td>
                            <td style="padding: var(--space-sm); white-space: nowrap;"><code><?= sanitize($t['slug']) ?></code></td>
                            <td style="padding: var(--space-sm); white-space: nowrap;"><span class="badge"><?= sanitize($t['mollie_status']) ?></span></td>
                            <td style="padding: var(--space-sm); white-space: nowrap; font-size: 13px;"><?= $t['created_at'] ?></td>
                            <td style="padding: var(--space-sm); white-space: nowrap;">
                                <button class="btn btn-secondary btn-sm toggle-tenant-btn"
                                        data-tenant-id="<?= (int) $t['id'] ?>"
                                        data-active="<?= $isActive ? '1' : '0' ?>">
                                    <?= $isActive ? 'Uitschakelen' : 'Inschakelen' ?>
                                </button>
                                <a href="<?= BASE_URL ?>/superadmin/tenant/<?= (int) $t['id'] ?>" class="btn btn-secondary btn-sm" style="margin-left: 8px;">Bewerk</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="no-results" style="display: none; text-align: center; padding: var(--space-lg); color: var(--text-secondary);">Geen tenants gevonden.</p>
        <?php endif; ?>
    </div>
</div>

<script>
// Instant search - filter tenants as you type
(function() {
    const searchInput = document.getElementById('tenant-search');
    const tbody = document.getElementById('tenants-tbody');
    const countEl = document.getElementById('tenant-count');
    const noResults = document.getElementById('no-results');
    if (!searchInput || !tbody) return;

    let debounceTimer;

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            const query = this.value.trim().toLowerCase();
            const rows = tbody.querySelectorAll('tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchData = (row.dataset.search || '').toLowerCase();
                const match = !query || searchData.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            if (countEl) countEl.textContent = visibleCount;
            if (noResults) noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }, 150);
    });
})();

// Show/hide create tenant form
document.getElementById('show-create-form')?.addEventListener('click', function() {
    document.getElementById('create-tenant-modal').style.display = 'block';
    this.style.display = 'none';
});

// Cancel create tenant form
document.getElementById('cancel-create')?.addEventListener('click', function() {
    document.getElementById('create-tenant-modal').style.display = 'none';
    document.getElementById('show-create-form').style.display = 'inline-block';
});

document.getElementById('create-tenant-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('name').value.trim();
    const slug = document.getElementById('slug').value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const contact_name = document.getElementById('contact_name')?.value.trim() || '';
    const contact_email = document.getElementById('contact_email')?.value.trim() || '';
    const phone = document.getElementById('phone')?.value.trim() || null;
    const address = document.getElementById('address')?.value.trim() || null;
    const postal_code = document.getElementById('postal_code')?.value.trim() || null;
    const city = document.getElementById('city')?.value.trim() || null;
    const csrf = document.querySelector('input[name="csrf_token"]').value;

    // Client-side validation: contact_email and contact_name are required
    if (!contact_email) {
        alert('Contact e-mailadres is verplicht. Zonder e-mail kan geen welcome mail worden verstuurd.');
        document.getElementById('contact_email').focus();
        return;
    }
    if (!contact_name) {
        alert('Contactpersoon is verplicht.');
        document.getElementById('contact_name').focus();
        return;
    }

    // Disable submit button to prevent double-submit
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const origBtnText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Aanmaken...';

    try {
        const res = await fetch(window.__BASE_URL + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ 
                name, 
                slug, 
                contact_name, 
                contact_email, 
                phone, 
                address, 
                postal_code, 
                city 
            })
        });
        const data = await res.json();
        if (data.success && data.data) {
            // Show credentials modal instead of instant reload
            showCredentialsModal(data.data);
        } else {
            alert('Fout: ' + data.error);
        }
    } catch (err) {
        alert('Netwerkfout: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = origBtnText;
    }
});

/**
 * Show a modal with the newly created admin credentials
 */
function showCredentialsModal(result) {
    const tenant = result.tenant || {};
    const adminEmail = result.admin_email || '—';
    const adminPassword = result.admin_password || '—';

    // Create overlay
    const overlay = document.createElement('div');
    overlay.id = 'credentials-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';

    // Create modal card
    const card = document.createElement('div');
    card.style.cssText = 'background:#1e1e2e;border-radius:12px;padding:32px;max-width:480px;width:90%;color:#fff;box-shadow:0 20px 60px rgba(0,0,0,0.5);';

    // Escape values to prevent XSS
    const esc = (s) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const contact_email_esc = esc(document.getElementById('contact_email').value.trim());
    const tenant_name_esc = esc(tenant.name || '');
    const email_esc = esc(adminEmail);
    const password_esc = esc(adminPassword);

    card.innerHTML = `
        <h2 style="margin:0 0 8px 0;font-size:20px;">✅ Tenant aangemaakt!</h2>
        <p style="margin:0 0 20px 0;opacity:0.7;font-size:14px;">Er is een welcome mail verstuurd naar <strong>${contact_email_esc}</strong> met de inloggegevens.</p>
        <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:16px;margin-bottom:20px;">
            <p style="margin:0 0 8px 0;font-size:13px;opacity:0.6;">Tenant</p>
            <p style="margin:0 0 16px 0;font-size:16px;font-weight:600;">${tenant_name_esc}</p>
            <p style="margin:0 0 8px 0;font-size:13px;opacity:0.6;">Admin inloggegevens</p>
            <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:14px;">
                <span style="opacity:0.6;">E-mail:</span>
                <code style="background:rgba(255,193,7,0.15);padding:2px 8px;border-radius:4px;color:#FFC107;word-break:break-all;">${email_esc}</code>
                <span style="opacity:0.6;">Wachtwoord:</span>
                <code style="background:rgba(255,193,7,0.15);padding:2px 8px;border-radius:4px;color:#FFC107;word-break:break-all;">${password_esc}</code>
            </div>
        </div>
        <p style="margin:0 0 16px 0;font-size:13px;color:#f44336;">⚠️ Let op: Dit wachtwoord wordt maar één keer getoond. Deel het veilig met de tenant eigenaar.</p>
        <button id="credentials-close-btn" class="btn btn-primary" style="width:100%;">Sluiten & naar overzicht</button>
    `;

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    document.getElementById('credentials-close-btn').addEventListener('click', () => {
        window.location.reload();
    });

    // Also close on overlay click (outside card)
    overlay.addEventListener('click', (ev) => {
        if (ev.target === overlay) {
            window.location.reload();
        }
    });
}

// Close the modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('create-tenant-modal');
    const btn = document.getElementById('show-create-form');
    if (event.target === modal) {
        modal.style.display = 'none';
        if (btn) btn.style.display = 'inline-block';
    }
});

// Toggle tenant active/inactive
document.querySelectorAll('.toggle-tenant-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const tenantId = parseInt(this.dataset.tenantId);
        const currentActive = this.dataset.active === '1';
        const newActive = currentActive ? 0 : 1;
        const csrf = document.querySelector('input[name="csrf_token"]').value;

        try {
            const res = await fetch(window.__BASE_URL + '/api/superadmin/tenants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ action: 'update', tenant_id: tenantId, is_active: newActive })
            });
            const result = await res.json();
            if (result.success) {
                // Update badge
                const badge = document.querySelector('.tenant-status-badge[data-tenant-id="' + tenantId + '"]');
                if (badge) {
                    badge.textContent = newActive ? 'Actief' : 'Inactief';
                    badge.style.background = newActive ? 'rgba(76,175,80,0.2)' : 'rgba(244,67,54,0.2)';
                    badge.style.color = newActive ? '#4CAF50' : '#f44336';
                }
                // Update button
                this.dataset.active = newActive ? '1' : '0';
                this.textContent = newActive ? 'Uitschakelen' : 'Inschakelen';
                this.style.background = newActive ? 'rgba(244,67,54,0.15)' : 'rgba(76,175,80,0.15)';
                this.style.color = newActive ? '#f44336' : '#4CAF50';
            } else {
                alert('Fout: ' + (result.error || 'Onbekend'));
            }
        } catch (err) {
            alert('Netwerkfout: ' + err.message);
        }
    });
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
