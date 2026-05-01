<?php
/**
 * REGULR.vip - Admin Instellingen
 * Admin: tenant instellingen beheren
 */
declare(strict_types=1);

$firstName      = $_SESSION['first_name'] ?? 'Admin';
$tenantName     = $_SESSION['tenant_name'] ?? APP_NAME;
$tenantId       = (int) ($_SESSION['tenant_id'] ?? 0);

// Haal de volledige tenant direct uit de database voor de meest actuele data
$db             = Database::getInstance()->getConnection();
$tenantModel    = new Tenant($db);
$tenant         = $tenantModel->findById($tenantId);

// Fallback op session-waarden als de DB-query faalt
if (!$tenant) {
    $tenant = [
        'name'              => $tenantName,
        'brand_color'       => $_SESSION['brand_color'] ?? '#FFC107',
        'secondary_color'   => $_SESSION['secondary_color'] ?? '#FF9800',
        'logo_path'         => $_SESSION['tenant_logo'] ?? '',
        'mollie_status'     => 'mock',
        'whitelisted_ips'   => '',
        'feature_push'      => true,
        'feature_marketing' => true,
    ];
}
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
                <div id="logo-preview-container" style="border: 2px dashed rgba(255,255,255,0.2); padding: var(--space-md); text-align: center; border-radius: 8px; min-height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #0f0f0f;">
                    <img id="logo-preview" src="<?= !empty($tenant['logo_path']) ? htmlspecialchars($tenant['logo_path']) : '' ?>" alt="Logo" <?= empty($tenant['logo_path']) ? 'style="display:none; max-height: 80px; margin-bottom: var(--space-md);"' : 'style="max-height: 80px; margin-bottom: var(--space-md);"' ?>>
                    <input type="file" id="tenant-logo" name="tenant_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="margin-top: var(--space-sm);">
                    <p class="text-sm" style="margin-top: var(--space-xs); opacity: 0.7;">PNG, JPG, WebP of SVG, max 2MB</p>
                    <p class="text-sm" id="logo-remove-field" style="margin-top: var(--space-xs); opacity: 0.7; <?= empty($tenant['logo_path']) ? 'display:none;' : '' ?>">
                        <label style="cursor: pointer; color: #f44336;">
                            <input type="checkbox" id="logo-remove" name="logo_remove" value="1" style="margin-right: 4px;">Verwijder logo
                        </label>
                    </p>
                </div>
            </div>
        </div>

        <!-- QR Code voor Gasten -->
        <?php
        // Build full join URL for QR code
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $slug    = $tenant['slug'] ?? 'unknown';
        $joinUrl = "{$scheme}://{$host}" . BASE_URL . "/j/{$slug}";
        $qrImgUrl = BASE_URL . "/api/assets/generate_join_qr?size=300";
        ?>
        <div id="qr-section" style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">QR Code voor Gasten</h2>
            <p class="text-sm" style="color: var(--text-secondary); margin-bottom: var(--space-md);">
                Print deze QR code en plaats op tafels, bierviltjes of aan de wand. Gasten die scannen komen direct op jouw registratiepagina.
            </p>
            <div class="form-group">
                <label>Registratie URL</label>
                <input type="text" class="form-input" value="<?= sanitize($joinUrl) ?>" readonly onclick="this.select();" style="opacity: 0.7; cursor: text;">
                <small class="help-text">Klik om te selecteren. Deze URL staat in de QR code.</small>
            </div>
            <div style="text-align: center; margin-top: var(--space-lg);">
                <!-- QR Code (printbaar gebied) -->
                <div id="qr-print-area" style="padding: 24px; background: #ffffff; border-radius: 12px; display: inline-block; max-width: 400px;">
                    <img src="<?= $qrImgUrl ?>" alt="QR Code - <?= sanitize($joinUrl) ?>" style="width: 300px; height: 300px; display: block; margin: 0 auto;">
                    <p style="color: #000; font-family: Inter, sans-serif; font-size: 14px; margin-top: 12px; font-weight: 600;"><?= sanitize($tenant['name'] ?? 'REGULR.vip') ?></p>
                    <p style="color: #666; font-family: Inter, sans-serif; font-size: 12px; margin-top: 4px;">Scan om je aan te melden</p>
                </div>
                <br>
                <div style="margin-top: var(--space-md); display: flex; gap: var(--space-sm); justify-content: center; flex-wrap: wrap;">
                    <a href="<?= $qrImgUrl ?>" class="btn btn-primary" download="qr-<?= sanitize($slug) ?>.png">Download QR Code (PNG)</a>
                    <button type="button" onclick="printQR()" class="btn btn-secondary">Print QR Code</button>
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

        <!-- Verificatie Limieten (Gated Onboarding) -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Verificatie Limieten</h2>
            <p class="text-sm" style="color: var(--text-secondary); margin-bottom: var(--space-md);">Configureer hoeveel gasten een barman per uur mag verifieren en hoe vaak een gast het mag proberen.</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label for="verification-soft-limit">Waarschuwingslimiet per barman/uur</label>
                    <input type="number" id="verification-soft-limit" class="form-input" value="<?= (int) ($tenant['verification_soft_limit'] ?? 15) ?>" min="3">
                </div>
                <div class="form-group">
                    <label for="verification-max-attempts">Max pogingen per gast/24u</label>
                    <input type="number" id="verification-max-attempts" class="form-input" value="<?= (int) ($tenant['verification_max_attempts'] ?? 2) ?>" min="1" max="10">
                </div>
            </div>
            
            <div class="form-group">
                <label for="verification-cooldown-sec">Cooldown na mismatch (sec)</label>
                <input type="number" id="verification-cooldown-sec" class="form-input" value="<?= (int) ($tenant['verification_cooldown_sec'] ?? 180) ?>" min="0" max="600">
            </div>
        </div>
        <!-- Features (READ-ONLY — bepaald door platform beheerder) -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Modules
                <span class="text-sm" style="font-weight:400; color: var(--text-secondary);">— Beheerd door platform beheerder</span>
            </h2>
            <div style="display: flex; gap: var(--space-md);">
                <span class="badge" style="padding: 8px 16px; border-radius: 20px; background: <?= ($tenant['feature_push'] ?? true) ? 'rgba(76,175,80,0.2); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3)' : 'rgba(244,67,54,0.2); color: #f44336; border: 1px solid rgba(244,67,54,0.3)' ?>;">
                    <?= ($tenant['feature_push'] ?? true) ? '✓' : '✗' ?> Push Notificaties
                </span>
                <span class="badge" style="padding: 8px 16px; border-radius: 20px; background: <?= ($tenant['feature_marketing'] ?? true) ? 'rgba(76,175,80,0.2); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3)' : 'rgba(244,67,54,0.2); color: #f44336; border: 1px solid rgba(244,67,54,0.3)' ?>;">
                    <?= ($tenant['feature_marketing'] ?? true) ? '✓' : '✗' ?> Marketing Studio
                </span>
                <span class="badge" style="padding: 8px 16px; border-radius: 20px; background: <?= ($tenant['verification_required'] ?? true) ? 'rgba(76,175,80,0.2); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3)' : 'rgba(158,158,158,0.2); color: #9e9e9e; border: 1px solid rgba(158,158,158,0.3)' ?>;">
                    <?= ($tenant['verification_required'] ?? true) ? '✓' : '✗' ?> ID-Verificatie
                </span>
            </div>
        </div>

        <!-- Actions -->
        <div style="text-align: center;">
            <button type="submit" class="btn btn-primary btn-lg">Opslaan</button>
        </div>
    </form>

    <div style="text-align: center; margin-top: var(--space-xl);">
        <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<!-- Print function for QR code -->
<script>
function printQR() {
    var printArea = document.getElementById('qr-print-area');
    if (printArea) {
        var printWindow = window.open('', '_blank', 'width=800,height=900');
        printWindow.document.write('<html><head><title>QR Code</title></head><body style="margin: 40px; text-align: center;">' + printArea.innerHTML + '</body></html>');
        printWindow.document.close();
        printWindow.print();
    }
}
</script>

<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js"></script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
