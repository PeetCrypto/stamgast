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

        <!-- Punten Systeem (admin-editable) -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Punten Systeem</h2>
            <p class="text-sm" style="color: var(--text-secondary); margin-bottom: var(--space-md);">
                Bepaal of je gasten punten kunnen sparen bij betalingen. Bestaande punten blijven bewaard, ook als je het systeem uitschakelt.
            </p>
            <div id="points-toggle-container" style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); border-radius: 12px; background: <?= ($tenant['points_enabled'] ?? true) ? 'rgba(76,175,80,0.06)' : 'rgba(255,193,7,0.06)' ?>; border: 1px solid <?= ($tenant['points_enabled'] ?? true) ? 'rgba(76,175,80,0.2)' : 'rgba(255,193,7,0.2)' ?>;">
                <label style="position: relative; display: inline-block; width: 52px; height: 28px; flex-shrink: 0; cursor: pointer;">
                    <input type="checkbox" id="points-enabled" <?= ($tenant['points_enabled'] ?? true) ? 'checked' : '' ?> style="opacity: 0; width: 0; height: 0; position: absolute;">
                    <span id="points-slider-track" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: <?= ($tenant['points_enabled'] ?? true) ? 'var(--accent-primary, #FFC107)' : '#666' ?>; border-radius: 28px; transition: background-color .3s;">
                        <span id="points-slider-knob" style="position: absolute; height: 22px; width: 22px; left: <?= ($tenant['points_enabled'] ?? true) ? '26px' : '3px' ?>; bottom: 3px; background-color: white; border-radius: 50%; transition: left .3s;"></span>
                    </span>
                </label>
                <div>
                    <strong id="points-status-label"><?= ($tenant['points_enabled'] ?? true) ? 'Punten sparen is ingeschakeld' : 'Punten sparen is uitgeschakeld' ?></strong>
                    <p class="text-sm" style="color: var(--text-secondary); margin-top: 2px;" id="points-desc-label">
                        <?= ($tenant['points_enabled'] ?? true) ? 'Je gasten sparen punten bij elke betaling.' : 'Je gasten sparen geen punten bij betalingen.' ?>
                    </p>
                </div>
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
                <span id="points-badge" class="badge" style="padding: 8px 16px; border-radius: 20px; background: <?= ($tenant['points_enabled'] ?? true) ? 'rgba(76,175,80,0.2); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3)' : 'rgba(244,67,54,0.2); color: #f44336; border: 1px solid rgba(244,67,54,0.3)' ?>;">
                    <?= ($tenant['points_enabled'] ?? true) ? '✓' : '✗' ?> Punten Systeem
                </span>
            </div>
        </div>

        <!-- Data Beheer -->
        <div style="margin-bottom: var(--space-xl);">
            <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Data Beheer</h2>
            <p class="text-sm" style="color: var(--text-secondary); margin-bottom: var(--space-md);">
                Verwijder oude push geschiedenis, verzonden marketing e-mails en systeem notificaties ouder dan 30 dagen.
                Transacties en financiële gegevens worden <strong>nooit</strong> verwijderd.
            </p>
            <div style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); border-radius: 12px; background: rgba(255,193,7,0.04); border: 1px solid rgba(255,193,7,0.15);">
                <div style="flex: 1;">
                    <strong>Oude data opruimen</strong>
                    <p class="text-sm" style="color: var(--text-secondary); margin-top: 2px;">
                        Verwijdert: push logs, verzonden/mislukte e-mails, systeem notificaties ouder dan 30 dagen
                    </p>
                    <div id="cleanup-result" style="display:none; margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); border-radius: 8px; font-size: 13px;"></div>
                </div>
                <button type="button" class="btn btn-secondary" id="cleanup-btn">Opruimen</button>
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

<!-- Points Toggle Confirmation Modal -->
<div id="points-confirm-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);justify-content:center;align-items:center;">
    <div style="background:linear-gradient(145deg,#1a1a2e,#16213e);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:32px;max-width:440px;width:90%;box-shadow:0 25px 50px rgba(0,0,0,0.5);text-align:center;">
        <div style="width:64px;height:64px;margin:0 auto var(--space-md);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;" id="points-modal-icon-wrap">
            <span id="points-modal-icon">⚠️</span>
        </div>
        <h3 style="margin:0 0 12px;font-size:20px;color:#fff;" id="points-modal-title">Let op!</h3>
        <p style="color:rgba(255,255,255,0.65);margin:0 0 28px;line-height:1.65;font-size:15px;" id="points-modal-message"></p>
        <div style="display:flex;gap:12px;justify-content:center;">
            <button id="points-modal-cancel" style="padding:12px 28px;border-radius:12px;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);cursor:pointer;font-size:15px;font-weight:500;transition:all .2s;">Annuleren</button>
            <button id="points-modal-confirm" style="padding:12px 28px;border-radius:12px;border:none;cursor:pointer;font-size:15px;font-weight:600;transition:all .2s;" class="btn btn-primary">Doorgaan</button>
        </div>
    </div>
</div>

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

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js?v=<?= filemtime(PUBLIC_PATH . 'js/admin.js') ?>"></script>

<!-- Points toggle confirmation + instant save (MUST load after app.js for REGULR.api) -->
<script>
(function() {
    var checkbox = document.getElementById('points-enabled');
    if (!checkbox) return;

    var originalState = checkbox.checked;

    function showPointsConfirm(message, enabling) {
        return new Promise(function(resolve) {
            var modal = document.getElementById('points-confirm-modal');
            var msgEl = document.getElementById('points-modal-message');
            var titleEl = document.getElementById('points-modal-title');
            var iconWrap = document.getElementById('points-modal-icon-wrap');
            var iconEl = document.getElementById('points-modal-icon');
            var confirmBtn = document.getElementById('points-modal-confirm');
            var cancelBtn = document.getElementById('points-modal-cancel');

            msgEl.textContent = message;
            titleEl.textContent = enabling ? 'Punten Systeem Inschakelen' : 'Punten Systeem Uitschakelen';
            iconEl.textContent = enabling ? '⭐' : '⚠️';
            iconWrap.style.background = enabling ? 'rgba(76,175,80,0.15)' : 'rgba(255,193,7,0.15)';

            modal.style.display = 'flex';

            function cleanup() {
                modal.style.display = 'none';
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
                modal.removeEventListener('click', onBackdrop);
            }
            function onConfirm() { cleanup(); resolve(true); }
            function onCancel() { cleanup(); resolve(false); }
            function onBackdrop(e) { if (e.target === modal) { cleanup(); resolve(false); } }

            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click', onCancel);
            modal.addEventListener('click', onBackdrop);
        });
    }

    function updateSliderVisual(on) {
        var track = document.getElementById('points-slider-track');
        var knob = document.getElementById('points-slider-knob');
        var container = document.getElementById('points-toggle-container');
        var badge = document.getElementById('points-badge');
        if (track) track.style.backgroundColor = on ? 'var(--accent-primary, #FFC107)' : '#666';
        if (knob) knob.style.left = on ? '26px' : '3px';
        if (container) {
            container.style.background = on ? 'rgba(76,175,80,0.06)' : 'rgba(255,193,7,0.06)';
            container.style.borderColor = on ? 'rgba(76,175,80,0.2)' : 'rgba(255,193,7,0.2)';
        }
        if (badge) {
            badge.textContent = (on ? '✓' : '✗') + ' Punten Systeem';
            badge.style.background = on ? 'rgba(76,175,80,0.2)' : 'rgba(244,67,54,0.2)';
            badge.style.color = on ? '#4CAF50' : '#f44336';
            badge.style.border = on ? '1px solid rgba(76,175,80,0.3)' : '1px solid rgba(244,67,54,0.3)';
        }
    }

    function doSave(enabling) {
        window.REGULR.api('/admin/settings', {
            method: 'POST',
            body: { points_enabled: enabling ? 1 : 0 }
        })
        .then(function(result) {
            originalState = enabling;
            var statusLabel = document.getElementById('points-status-label');
            var descLabel = document.getElementById('points-desc-label');
            if (statusLabel) statusLabel.textContent = enabling ? 'Punten sparen is ingeschakeld' : 'Punten sparen is uitgeschakeld';
            if (descLabel) descLabel.textContent = enabling ? 'Je gasten sparen punten bij elke betaling.' : 'Je gasten sparen geen punten bij betalingen.';
            updateSliderVisual(enabling);
            window.REGULR.showSuccess(enabling ? 'Puntenysteem ingeschakeld voor al je klanten' : 'Puntenysteem uitgeschakeld — punten zijn niet meer beschikbaar');
        })
        .catch(function(err) {
            checkbox.checked = originalState;
            updateSliderVisual(originalState);
            window.REGULR.showError(err.message || 'Fout bij opslaan');
        });
    }

    checkbox.addEventListener('change', function() {
        var enabling = checkbox.checked;

        var message = enabling
            ? 'Het puntenysteem wordt geactiveerd voor al je klanten. Gasten kunnen vanaf nu punten sparen bij betalingen.'
            : 'Wanneer je het puntenysteem uitschakelt, zijn de gespaarde punten niet meer beschikbaar voor je gasten. Bestaande punten blijven wel bewaard in het systeem.';

        showPointsConfirm(message, enabling).then(function(confirmed) {
            if (!confirmed) {
                checkbox.checked = !enabling;
                updateSliderVisual(!enabling);
                return;
            }
            doSave(enabling);
        });
    });
})();

// Cleanup handler
(function() {
    var btn = document.getElementById('cleanup-btn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        if (!confirm('Weet je zeker dat je data ouder dan 30 dagen wilt opruimen?\n\nDit verwijdert:\n• Push verzendgeschiedenis ouder dan 30 dagen\n• Verzonden/mislukte marketing e-mails ouder dan 30 dagen\n• Systeem notificaties ouder dan 30 dagen\n\nTransacties blijven altijd behouden.')) {
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Bezig...';

        var resultEl = document.getElementById('cleanup-result');
        if (resultEl) { resultEl.style.display = 'none'; }

        window.REGULR.api('/admin/cleanup', {
            method: 'POST',
            body: { days: 30 }
        })
        .then(function(result) {
            if (result.success) {
                var d = result.data;
                var total = d.total || 0;
                btn.textContent = 'Opruimen';

                if (resultEl) {
                    if (total === 0) {
                        resultEl.style.display = 'block';
                        resultEl.style.background = 'rgba(76,175,80,0.1)';
                        resultEl.style.color = '#4CAF50';
                        resultEl.textContent = '✓ Geen oude data gevonden. Alles is al schoon!';
                    } else {
                        resultEl.style.display = 'block';
                        resultEl.style.background = 'rgba(76,175,80,0.1)';
                        resultEl.style.color = '#4CAF50';
                        var parts = [];
                        if (d.deleted.audit_log_push > 0) parts.push(d.deleted.audit_log_push + ' push logs');
                        if (d.deleted.email_queue_sent_failed > 0) parts.push(d.deleted.email_queue_sent_failed + ' e-mails');
                        if (d.deleted.notifications_system > 0) parts.push(d.deleted.notifications_system + ' notificaties');
                        if (d.deleted.notifications_soft_deleted > 0) parts.push(d.deleted.notifications_soft_deleted + ' verwijderde notificaties');
                        resultEl.textContent = '✓ ' + total + ' records opgeruimd: ' + parts.join(', ');
                    }
                }
                window.REGULR.showSuccess('Opruiming voltooid: ' + total + ' records verwijderd');
            } else {
                throw new Error(result.error || 'Onbekende fout');
            }
        })
        .catch(function(err) {
            btn.textContent = 'Opruimen';
            window.REGULR.showError('Opruimen mislukt: ' + (err.message || 'Onbekende fout'));
        })
        .finally(function() {
            btn.disabled = false;
        });
    });
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
