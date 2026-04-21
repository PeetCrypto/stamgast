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

$tenants = $tenantModel->getAll();
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>Tenant Beheer</h1>
        <a href="/superadmin" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Create Tenant Form -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
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
            <button type="submit" class="btn btn-primary">Aanmaken</button>
        </form>
    </div>

    <!-- Tenants Table -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Bestaande Tenants (<?= count($tenants) ?>)</h2>
        <?php if (empty($tenants)): ?>
            <p class="text-secondary">Nog geen tenants.</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <th style="text-align: left; padding: var(--space-sm);">ID</th>
                        <th style="text-align: left; padding: var(--space-sm);">Naam</th>
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
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: var(--space-sm);"><?= (int) $t['id'] ?></td>
                        <td style="padding: var(--space-sm);"><?= sanitize($t['name']) ?></td>
                        <td style="padding: var(--space-sm); font-size: 13px;">
                            <?= sanitize($t['contact_name'] ?? '') ?>
                            <br><small><?= sanitize($t['contact_email'] ?? '') ?></small>
                        </td>
                        <td style="padding: var(--space-sm); text-align: center;">
                            <?= (int) $t['user_count'] ?>
                        </td>
                        <td style="padding: var(--space-sm);"><code><?= sanitize($t['slug']) ?></code></td>
                        <td style="padding: var(--space-sm);"><span class="badge"><?= sanitize($t['mollie_status']) ?></span></td>
                        <td style="padding: var(--space-sm);"><?= $t['created_at'] ?></td>
                        <td style="padding: var(--space-sm);">
                            <a href="/superadmin/tenant/<?= (int) $t['id'] ?>" class="btn btn-secondary btn-sm">Bewerk</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
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
        const res = await fetch('/api/superadmin/tenants', {
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
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
