<?php
/**
 * STAMGAST - Admin Instellingen
 * Admin: tenant instellingen beheren
 */
$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$tenant = $_SESSION['tenant'] ?? null;
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg); text-align: center;">Instellingen</h1>

    <!-- Settings Form -->
    <form class="glass-card" id="settings-form" style="padding: var(--space-lg); max-width: 800px; margin: 0 auto;">
        
        <!-- Algemeen -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Algemeen</h2>
            
            <div class="form-group">
                <label for="tenant-name">Naam</label>
                <input type="text" id="tenant-name" class="form-input" value="<?= htmlspecialchars($tenant['name'] ?? '') ?>" readonly>
            </div>
        </div>

        <!-- Uiterlijk -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Uiterlijk</h2>
            
            <div class="form-group">
                <label for="brand-color">Hoofdkleur</label>
                <div style="display: flex; gap: var(--space-md); align-items: center;">
                    <input type="color" id="brand-color" value="<?= htmlspecialchars($tenant['brand_color'] ?? '#FFC107') ?>" style="width: 60px; height: 40px; border: none; cursor: pointer;">
                    <input type="text" id="brand-color-hex" class="form-input" value="<?= htmlspecialchars($tenant['brand_color'] ?? '#FFC107') ?>" style="max-width: 120px;">
                </div>
            </div>
            
            <div class="form-group">
                <label for="secondary-color">Secundaire kleur</label>
                <div style="display: flex; gap: var(--space-md); align-items: center;">
                    <input type="color" id="secondary-color" value="<?= htmlspecialchars($tenant['secondary_color'] ?? '#FF9800') ?>" style="width: 60px; height: 40px; border: none; cursor: pointer;">
                    <input type="text" id="secondary-color-hex" class="form-input" value="<?= htmlspecialchars($tenant['secondary_color'] ?? '#FF9800') ?>" style="max-width: 120px;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Logo</label>
                <div style="border: 2px dashed rgba(255,255,255,0.2); padding: var(--space-md); text-align: center; border-radius: 8px;">
                    <?php if (!empty($tenant['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($tenant['logo_path']) ?>" alt="Logo" style="max-height: 80px; margin-bottom: var(--space-md);">
                    <?php endif; ?>
                    <input type="file" id="tenant-logo" accept="image/*" style="margin-top: var(--space-sm);">
                    <p class="text-sm" style="margin-top: var(--space-xs); opacity: 0.7;">PNG of JPG, max 1MB</p>
                </div>
            </div>
        </div>

        <!-- Betalingen -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Betalingen</h2>
            
            <div class="form-group">
                <label for="mollie-api-key">Mollie API Key</label>
                <input type="password" id="mollie-api-key" class="form-input" value="<?= htmlspecialchars(substr($tenant['mollie_api_key'] ?? '', 0, 20) . '...') ?>" placeholder="test_...">
                <small class="help-text">Begint met test_ of live_</small>
            </div>
            
            <div class="form-group">
                <label for="mollie-status">Modus</label>
                <select id="mollie-status" class="form-input">
                    <option value="mock" <?= ($tenant['mollie_status'] ?? 'mock') === 'mock' ? 'selected' : '' ?>>Mock (test)</option>
                    <option value="test" <?= ($tenant['mollie_status'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                    <option value="live" <?= ($tenant['mollie_status'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
                </select>
            </div>
        </div>

        <!-- POS -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">POS Configuratie</h2>
            
            <div class="form-group">
                <label for="whitelisted-ips">Toegestane IPs</label>
                <textarea id="whitelisted-ips" class="form-input" rows="4" placeholder="192.168.1.1&#10;10.0.0.1" style="width: 100%;"><?= htmlspecialchars($tenant['whitelisted_ips'] ?? '') ?></textarea>
                <small class="help-text">Een IP per regel. Gebruik voor barcode scanners.</small>
            </div>
        </div>

        <!-- Features -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Modules</h2>
            
            <div style="display: flex; flex-direction: column; gap: var(--space-md);">
                <label style="display: flex; align-items: center; gap: var(--space-md); cursor: pointer;">
                    <input type="checkbox" id="feature-push" <?= ($tenant['feature_push'] ?? true) ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                    <span>Push Notificaties</span>
                </label>
                
                <label style="display: flex; align-items: center; gap: var(--space-md); cursor: pointer;">
                    <input type="checkbox" id="feature-marketing" <?= ($tenant['feature_marketing'] ?? true) ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                    <span>Marketing Studio</span>
                </label>
            </div>
        </div>

        <!-- Actions -->
        <div style="text-align: center;">
            <button type="submit" class="btn btn-primary btn-lg">Opslaan</button>
        </div>
    </form>

    <div style="text-align: center; margin-top: var(--space-xl);">
        <a href="/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
<script src="/public/js/app.js"></script>
<script src="/public/js/admin.js"></script>
</body>
</html>