<?php
declare(strict_types=1);
/**
 * Superadmin - Tenant Management
 * STAMGAST Loyalty Platform
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

        // Validate NAW fields if present
        if (isset($input['contact_email']) && !empty($input['contact_email'])) {
            if (!filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL)) {
                Response::error('Ongeldig contact e-mailadres', 'INVALID_EMAIL', 400);
            }
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

// Handle search parameter
$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    // Search tenants by name
    $stmt = $db->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id`) AS user_count,
                (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id` AND u.`role` = \'guest\') AS guest_count,
                (SELECT COUNT(*) FROM `users` u WHERE u.`tenant_id` = t.`id` AND u.`role` IN (\'admin\',\'bartender\')) AS staff_count
         FROM `tenants` t
         WHERE t.`name` LIKE :search
         ORDER BY t.`created_at` DESC'
    );
    $stmt->execute([':search' => '%' . $search . '%']);
    $tenants = $stmt->fetchAll();
} else {
    $tenants = $tenantModel->getAllWithUserCount();
}
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
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
                <label for="contact_name">Contactpersoon</label>
                <input type="text" id="contact_name" class="form-input" placeholder="Bijv. Jan Jansen">
            </div>
            <div class="form-group">
                <label for="contact_email">Contact E-mail</label>
                <input type="email" id="contact_email" class="form-input" placeholder="jan@jansen.nl">
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
        <h2 style="margin-bottom: var(--space-md);">Bestaande Tenants (<?= count($tenants) ?>)</h2>
        
        <!-- Search Form -->
        <div style="margin-bottom: var(--space-md);">
            <form method="GET" id="tenant-search-form" style="display: flex; gap: var(--space-sm);">
                <input type="text" 
                       name="search" 
                       placeholder="Zoek op naam..." 
                       value="<?= htmlspecialchars($search) ?>"
                       style="flex: 1; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: white;"
                       autocomplete="off">
                <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Zoeken</button>
                <?php if (!empty($search)): ?>
                    <a href="<?= BASE_URL ?>/superadmin/tenants" class="btn btn-secondary" style="padding: 10px 20px;">Wis</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div style="margin: var(--space-md) 0;">
            <button id="show-create-form" class="btn btn-primary">+ Nieuwe Tenant</button>
        </div>
        
        <?php if (empty($tenants)): ?>
            <p class="text-secondary">Nog geen tenants.</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <th style="text-align: left; padding: var(--space-sm);">ID</th>
                        <th style="text-align: left; padding: var(--space-sm);">Naam</th>
                        <th style="text-align: left; padding: var(--space-sm);">Status</th>
                        <th style="text-align: left; padding: var(--space-sm);">Contact</th>
                        <th style="text-align: left; padding: var(--space-sm);">Gebruikers</th>
                        <th style="text-align: left; padding: var(--space-sm);">Slug</th>
                        <th style="text-align: left; padding: var(--space-sm);">Mollie</th>
                        <th style="text-align: left; padding: var(--space-sm);">Aangemaakt</th>
                        <th style="text-align: left; padding: var(--space-sm);">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenants as $t): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);" data-tenant-id="<?= (int) $t['id'] ?>">
                        <td style="padding: var(--space-sm);"><?= (int) $t['id'] ?></td>
                        <td style="padding: var(--space-sm);"><?= sanitize($t['name']) ?></td>
                        <td style="padding: var(--space-sm);">
                            <?php $isActive = (bool) ($t['is_active'] ?? true); ?>
                            <span class="badge tenant-status-badge" data-tenant-id="<?= (int) $t['id'] ?>" style="background:<?= $isActive ? 'rgba(76,175,80,0.2)' : 'rgba(244,67,54,0.2)' ?>;color:<?= $isActive ? '#4CAF50' : '#f44336' ?>;cursor:default;">
                                <?= $isActive ? 'Actief' : 'Inactief' ?>
                            </span>
                        </td>
                        <td style="padding: var(--space-sm); font-size: 13px;">
                            <?= sanitize($t['contact_name'] ?? '') ?>
                            <br><small><?= sanitize($t['contact_email'] ?? '') ?></small>
                        </td>
                        <td style="padding: var(--space-sm); text-align: center;">
                            <?= (int) ($t['user_count'] ?? 0) ?>
                        </td>
                        <td style="padding: var(--space-sm);"><code><?= sanitize($t['slug']) ?></code></td>
                        <td style="padding: var(--space-sm);"><span class="badge"><?= sanitize($t['mollie_status']) ?></span></td>
                        <td style="padding: var(--space-sm);"><?= $t['created_at'] ?></td>
                        <td style="padding: var(--space-sm);">
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
        <?php endif; ?>
    </div>
</div>

<script>
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
    const contact_name = document.getElementById('contact_name')?.value.trim() || null;
    const contact_email = document.getElementById('contact_email')?.value.trim() || null;
    const phone = document.getElementById('phone')?.value.trim() || null;
    const address = document.getElementById('address')?.value.trim() || null;
    const postal_code = document.getElementById('postal_code')?.value.trim() || null;
    const city = document.getElementById('city')?.value.trim() || null;
    const csrf = document.querySelector('input[name="csrf_token"]').value;

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
        if (data.success) {
            window.location.reload();
        } else {
            alert('Fout: ' + data.error);
        }
} catch (err) {
        alert('Netwerkfout: ' + err.message);
    }
});

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
