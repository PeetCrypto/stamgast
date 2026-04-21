<?php
/**
 * STAMGAST - Admin Instellingen
 * Admin: tenant instellingen beheren
 */
require_once __DIR__ . '/../shared/header.php';

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;
?>
<body class="admin-page settings-page">
    <main class="main-container">
        <!-- Header -->
        <div class="page-header">
            <h1>Instellingen</h1>
            <p class="text-muted">Beheer je etablissement</p>
        </div>

        <!-- Settings Form -->
        <form class="settings-form glass-card" id="settings-form">
            <!-- Algemeen -->
            <section class="settings-section">
                <h2>Algemeen</h2>
                
                <div class="form-group">
                    <label for="tenant-name">Naam</label>
                    <input type="text" id="tenant-name" value="<?= htmlspecialchars($tenant['name'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="tenant-slug">URL Slug</label>
                    <input type="text" id="tenant-slug" value="<?= htmlspecialchars($tenant['slug'] ?? '') ?>" readonly>
                    <small class="help-text">Gebruikt in URL: /stamgast/</small>
                </div>
            </section>

            <!-- Uiterlijk -->
            <section class="settings-section">
                <h2>Uiterlijk</h2>
                
                <div class="form-group">
                    <label for="brand-color">Hoofdkleur</label>
                    <div class="color-input">
                        <input type="color" id="brand-color" value="<?= htmlspecialchars($tenant['brand_color'] ?? '#FFC107') ?>">
                        <input type="text" id="brand-color-hex" value="<?= htmlspecialchars($tenant['brand_color'] ?? '#FFC107') ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="secondary-color"> Secundaire kleur</label>
                    <div class="color-input">
                        <input type="color" id="secondary-color" value="<?= htmlspecialchars($tenant['secondary_color'] ?? '#FF9800') ?>">
                        <input type="text" id="secondary-color-hex" value="<?= htmlspecialchars($tenant['secondary_color'] ?? '#FF9800') ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Logo</label>
                    <div class="logo-upload">
                        <?php if (!empty($tenant['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($tenant['logo_path']) ?>" alt="Logo" class="logo-preview">
                        <?php endif; ?>
                        <input type="file" id="tenant-logo" accept="image/*">
                        <small>PNG of JPG, max 1MB</small>
                    </div>
                </div>
            </section>

            <!-- Betalingen -->
            <section class="settings-section">
                <h2>Betalingen</h2>
                
                <div class="form-group">
                    <label for="mollie-api-key">Mollie API Key</label>
                    <input type="password" id="mollie-api-key" value="<?= htmlspecialchars(substr($tenant['mollie_api_key'] ?? '', 0, 20) . '...') ?>" placeholder="test_...">
                    <small class="help-text">Begint met test_ of live_</small>
                </div>
                
                <div class="form-group">
                    <label for="mollie-status">Modus</label>
                    <select id="mollie-status">
                        <option value="mock" <?= ($tenant['mollie_status'] ?? 'mock') === 'mock' ? 'selected' : '' ?>>Mock (test)</option>
                        <option value="test" <?= ($tenant['mollie_status'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                        <option value="live" <?= ($tenant['mollie_status'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
                    </select>
                </div>
            </section>

            <!-- POS -->
            <section class="settings-section">
                <h2>POS Configuratie</h2>
                
                <div class="form-group">
                    <label for="whitelisted-ips">Toegestane IPs</label>
                    <textarea id="whitelisted-ips" rows="4" placeholder="192.168.1.1&#10;10.0.0.1"><?= htmlspecialchars($tenant['whitelisted_ips'] ?? '') ?></textarea>
                    <small class="help-text">Een IP per regel. Gebruik voor barcode scanners.</small>
                </div>
            </section>

            <!-- Features -->
            <section class="settings-section">
                <h2>Modules</h2>
                
                <div class="toggle-group">
                    <label class="toggle">
                        <input type="checkbox" id="feature-push" <?= ($tenant['feature_push'] ?? true) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Push Notificaties</span>
                    </label>
                </div>
                
                <div class="toggle-group">
                    <label class="toggle">
                        <input type="checkbox" id="feature-marketing" <?= ($tenant['feature_marketing'] ?? true) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Marketing Studio</span>
                    </label>
                </div>
            </section>

            <!-- Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    Opslaan
                </button>
            </div>
        </form>
    </main>

    <!-- Alerts -->
    <div class="alerts-container"></div>

    <?php require_once __DIR__ . '/../shared/footer.php'; ?>
    <script src="/public/js/app.js"></script>
    <script src="/public/js/admin.js"></script>
</body>
</html>