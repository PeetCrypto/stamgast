<?php
declare(strict_types=1);
/**
 * Superadmin - Tenant Detail View
 * REGULR.vip Loyalty Platform
 * Shows: NAW info (editable), stats, users with role management
 */

require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);

// Extract tenant ID from URL: /superadmin/tenant/{id}
$route = trim($_GET['route'] ?? '', '/');
$parts = explode('/', $route);
$tenantId = (int) ($parts[2] ?? 0);

if ($tenantId <= 0) {
    http_response_code(404);
    require VIEWS_PATH . 'shared/header.php';
    echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Tenant niet gevonden</p><a href="' . BASE_URL . '/superadmin" class="btn btn-primary">Terug</a></div>';
    require VIEWS_PATH . 'shared/footer.php';
    exit;
}

$tenant = $tenantModel->findById($tenantId);
if (!$tenant) {
    http_response_code(404);
    require VIEWS_PATH . 'shared/header.php';
    echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Tenant niet gevonden</p><a href="' . BASE_URL . '/superadmin" class="btn btn-primary">Terug</a></div>';
    require VIEWS_PATH . 'shared/footer.php';
    exit;
}

$stats = $tenantModel->getTenantStats($tenantId);
$users = $tenantModel->getUsersWithWallets($tenantId);

// Fee config and stats
$feeConfig = $tenantModel->getFeeConfig($tenantId);
$feeService = new PlatformFeeService($db);
$feeStats = $feeService->getTenantFeeStats($tenantId);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 1100px; margin: 0 auto;">
    <!-- Navigation -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1><?= sanitize($tenant['name']) ?></h1>
        <div style="display:flex;gap:8px;align-items:center;">
            <button onclick="viewAsAdmin()" class="btn btn-primary">&#128065; Bekijk als beheerder</button>
            <a href="<?= BASE_URL ?>/superadmin/tenants" class="btn btn-secondary">&larr; Terug</a>
        </div>
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
                        <label class="text-sm text-secondary">Tijdzone</label>
                        <select id="naw-timezone" class="form-input">
                            <?php
                            require_once __DIR__ . '/../../services/TimezoneService.php';
                            $currentTz = $tenant['timezone'] ?? 'Europe/Amsterdam';
                            foreach (TimezoneService::getAllowedTimezones() as $tz):
                                $selected = ($tz === $currentTz) ? 'selected' : '';
                                // Leesbare label: vervang underscores en slashes
                                $label = str_replace(['_', '/'], [' ', ' / '], $tz);
                            ?>
                                <option value="<?= $tz ?>" <?= $selected ?>><?= $label ?></option>
                            <?php endforeach; ?>
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
                        <div class="form-group" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid rgba(255,255,255,0.1);">
                            <label class="text-sm text-secondary">ID-Verificatie verplicht</label>
                            <label style="display:flex;align-items:center;gap:var(--space-sm);cursor:pointer;">
                                <input type="checkbox" id="naw-verification_required" <?= ($tenant['verification_required'] ?? true) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span id="naw-verification_required-label"><?= ($tenant['verification_required'] ?? true) ? 'Verplicht' : 'Uitgeschakeld' ?></span>
                            </label>
                            <p class="text-sm text-secondary" style="margin-top:4px;">Wanneer uit: nieuwe gasten zijn direct actief na registratie. Bestaande unverified gasten blijven onveranderd.</p>
                        </div>
                        <div class="form-group" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid rgba(255,255,255,0.1);">
                            <label class="text-sm text-secondary">Test Modus</label>
                            <label style="display:flex;align-items:center;gap:var(--space-sm);cursor:pointer;">
                                <input type="checkbox" id="naw-is_test" <?= ($tenant['is_test'] ?? false) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span id="naw-is_test-label" style="color: <?= ($tenant['is_test'] ?? false) ? '#FF9800' : 'inherit' ?>;">
                                    <?= ($tenant['is_test'] ?? false) ? 'Test tenant' : 'Productie' ?>
                                </span>
                            </label>
                            <p class="text-sm text-secondary" style="margin-top:4px;">
                                Schakel in om test-data te kunnen wissen (gasten, pakketten, saldo) en om automatisch een <strong>testpakket (€0,01 → €10 bonus)</strong> toe te voegen voor het testen van live betalingen via Mollie. Bij uitschakelen verdwijnt het testpakket automatisch.
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:var(--space-sm);">Opslaan</button>
                    <p id="naw-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                </form>
            </div>

            <!-- Test Tenant: Danger Zone (always rendered, shown/hidden via JS) -->
            <div id="test-danger-zone" class="glass-card" style="padding: var(--space-lg); margin-top: var(--space-lg); border: 1px solid rgba(244,67,54,0.4); background: rgba(244,67,54,0.05); display: none;">
                <h2 style="margin-bottom: var(--space-md); color: #f44336;">⚠ Test Data Wissen</h2>
                <p class="text-sm text-secondary" style="margin-bottom: var(--space-md);">
                    Deze tenant is gemarkeerd als <strong>test tenant</strong>. Je kunt hieronder test-data wissen.
                </p>
                <div style="display: grid; gap: var(--space-sm);">
                    <button type="button" id="btn-purge-guests" class="btn" style="width:100%;background:rgba(244,67,54,0.15);color:#f44336;border:1px solid rgba(244,67,54,0.3);">
                        🗑 Verwijder alle gasten
                    </button>
                    <button type="button" id="btn-purge-packages" class="btn" style="width:100%;background:rgba(244,67,54,0.15);color:#f44336;border:1px solid rgba(244,67,54,0.3);">
                        🗑 Verwijder alle pakketten
                    </button>
                    <button type="button" id="btn-reset-balances" class="btn" style="width:100%;background:rgba(255,152,0,0.15);color:#FF9800;border:1px solid rgba(255,152,0,0.3);">
                        ↺ Reset saldo gasten naar €0
                    </button>
                </div>
                <p id="purge-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
            </div>

            <!-- Platform Fee Config -->
            <div class="glass-card" style="padding: var(--space-lg); margin-top: var(--space-lg);">
                <h2 style="margin-bottom: var(--space-md);">Platform Fee & Mollie Connect</h2>
                <form id="fee-form">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                        <div class="form-group">
                            <label class="text-sm text-secondary">Fee Percentage (%)</label>
                            <input type="number" id="fee-percentage" class="form-input" value="<?= number_format((float) $feeConfig['percentage'], 2) ?>" step="0.01" min="0" max="25">
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Minimum Fee (cents)</label>
                            <input type="number" id="fee-min-cents" class="form-input" value="<?= (int) $feeConfig['min_cents'] ?>" min="0" max="100000">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Factuur Periode</label>
                        <select id="fee-invoice-period" class="form-input">
                            <option value="month" <?= ($feeConfig['invoice_period'] ?? 'month') === 'month' ? 'selected' : '' ?>>Maandelijks</option>
                            <option value="week" <?= ($feeConfig['invoice_period'] ?? 'month') === 'week' ? 'selected' : '' ?>>Wekelijks</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">BTW Nummer</label>
                        <input type="text" id="fee-btw-number" class="form-input" value="<?= sanitize($feeConfig['btw_number'] ?? '') ?>" placeholder="bijv. 123456789B01">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Factuur E-mail</label>
                        <input type="email" id="fee-invoice-email" class="form-input" value="<?= sanitize($feeConfig['invoice_email'] ?? '') ?>" placeholder="facturen@tenant.nl">
                    </div>
                    <div class="form-group">
                        <label class="text-sm text-secondary">Interne Notitie</label>
                        <textarea id="fee-note" class="form-input" rows="2" placeholder="Notitie voor superadmin (niet zichtbaar voor tenant)"><?= sanitize($feeConfig['note'] ?? '') ?></textarea>
</div>
                     
                    <!-- Deposit Limits per Tenant -->
                    <div style="border-top: 1px solid var(--glass-border); padding-top: var(--space-md); margin-top: var(--space-md);">
                        <div class="form-group">
                            <label class="text-sm text-secondary" style="font-weight: 600; color: var(--accent-primary);">Minimale Opwaardering</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                                <div>
                                    <input type="number" id="fee-deposit-min" class="form-input" value="<?= (($tenant['deposit_min_cents'] ?? 10000) / 100) ?>" min="1" max="1000" step="1">
                                </div>
                                <div style="display: flex; align-items: center; color: var(--text-secondary); font-size: 14px;">
                                    euro
                                </div>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: var(--space-sm);">
                            <label class="text-sm text-secondary" style="font-weight: 600; color: var(--accent-primary);">Maximale Opwaardering</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                                <div>
                                    <input type="number" id="fee-deposit-max" class="form-input" value="<?= (($tenant['deposit_max_cents'] ?? 50000) / 100) ?>" min="100" max="5000" step="1">
                                </div>
                                <div style="display: flex; align-items: center; color: var(--text-secondary); font-size: 14px;">
                                    euro
                                </div>
                            </div>
                        </div>
                        <p class="text-sm text-secondary" style="margin-top:4px;">Stel in per tenant. Nieuwe tenants krijgen standaardwaarden: min €100, max €500.</p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width:100%;">Fee Config Opslaan</button>
                    <p id="fee-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                </form>

                <!-- Mollie Connect Status (read-only — koppelen gebeurt in admin omgeving) -->
                <div style="border-top: 1px solid var(--glass-border); padding-top: var(--space-md); margin-top: var(--space-lg);">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm); color: var(--accent-primary);">Mollie Connect</h3>
                    <?php
                    $connectStatus = $tenant['mollie_connect_status'] ?? 'none';
                    $connectColors = [
                        'none'     => 'rgba(158,158,158,0.2);color:#9e9e9e',
                        'pending'  => 'rgba(255,152,0,0.2);color:#FF9800',
                        'active'   => 'rgba(76,175,80,0.2);color:#4CAF50',
                        'suspended'=> 'rgba(244,67,54,0.2);color:#f44336',
                        'revoked'  => 'rgba(244,67,54,0.2);color:#f44336',
                    ];
                    $connectLabels = ['none' => 'Niet gekoppeld', 'pending' => 'In afwachting', 'active' => 'Actief', 'suspended' => 'Onderbroken', 'revoked' => 'Ingetrokken'];
                    $sc = $connectColors[$connectStatus] ?? $connectColors['none'];
                    ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-sm);">
                        <span>Connect: <span class="badge" style="background:<?= $sc ?>;"><?= $connectLabels[$connectStatus] ?? $connectStatus ?></span></span>
                    </div>
                    <?php if (!empty($tenant['mollie_connect_id'])): ?>
                        <p class="text-sm text-secondary">Org ID: <code><?= sanitize($tenant['mollie_connect_id']) ?></code></p>
                    <?php endif; ?>

                    <?php
                    // Account status badge — gebaseerd op gecachte Mollie onboarding status.
                    // Wordt live ververst via /api/superadmin/mollie-status (zie JS onderaan).
                    $obStatus    = $tenant['mollie_connect_onboarding_status'] ?? null;
                    $canReceive  = isset($tenant['mollie_connect_can_receive_payments'])
                        ? (bool) $tenant['mollie_connect_can_receive_payments'] : null;
                    $checkedAt   = $tenant['mollie_connect_status_checked_at'] ?? null;

                    // Bepaal badge kleur + label op basis van onboarding data
                    if ($canReceive === true && $obStatus === 'completed') {
                        $acctColor = 'rgba(76,175,80,0.2);color:#4CAF50';
                        $acctLabel = 'Live';
                    } elseif ($obStatus === 'blocked') {
                        $acctColor = 'rgba(244,67,54,0.2);color:#f44336';
                        $acctLabel = 'Geblokkeerd';
                    } elseif ($obStatus === 'in-review') {
                        $acctColor = 'rgba(255,152,0,0.2);color:#FF9800';
                        $acctLabel = 'In behandeling';
                    } elseif ($obStatus === 'needs-data' || ($obStatus !== null && $canReceive === false)) {
                        $acctColor = 'rgba(244,67,54,0.2);color:#f44336';
                        $acctLabel = 'Onvolledig';
                    } else {
                        $acctColor = 'rgba(158,158,158,0.2);color:#9e9e9e';
                        $acctLabel = 'Onbekend';
                    }

                    // Format "laatst gecontroleerd" timestamp (UTC → lokaal)
                    $checkedLabel = '';
                    if (!empty($checkedAt)) {
                        try {
                            $dt = new DateTime($checkedAt, new DateTimeZone('UTC'));
                            $dt->setTimezone(new DateTimeZone('Europe/Amsterdam'));
                            $checkedLabel = $dt->format('j M Y H:i');
                        } catch (Throwable $e) {
                            $checkedLabel = $checkedAt;
                        }
                    }
                    ?>
                    <div style="margin-bottom: var(--space-sm); display: flex; justify-content: space-between; align-items: center; gap: var(--space-sm);">
                        <span class="text-sm text-secondary">Account status:</span>
                        <span style="display:flex; align-items:center; gap:var(--space-xs);">
                            <span id="mollie-acct-badge" class="badge" style="background:<?= $acctColor ?>;" data-status="<?= sanitize((string) ($obStatus ?? '')) ?>" data-can-receive="<?= $canReceive === null ? '' : ($canReceive ? '1' : '0') ?>">
                                <?= $acctLabel ?>
                            </span>
                            <button type="button" id="mollie-acct-refresh" class="btn btn-secondary" style="padding:2px 8px; font-size:12px; line-height:1.4;">Verversen</button>
                        </span>
                    </div>
                    <p id="mollie-acct-checked" class="text-sm text-secondary" style="margin-top:0; margin-bottom:var(--space-sm); font-size:11px;">
                        <?php if ($checkedLabel): ?>
                            Laatst gecontroleerd: <?= sanitize($checkedLabel) ?>
                        <?php else: ?>
                            Nog niet gecontroleerd — klik "Verversen".
                        <?php endif; ?>
                    </p>
                    <p id="mollie-acct-detail" class="text-sm text-secondary" style="margin:0 0 var(--space-sm); font-size:11px;"></p>

                    <div class="form-group" style="margin-top: var(--space-sm);">
                        <label class="text-sm text-secondary">Betaalmodus</label>
                        <select id="mollie-mode-select" class="form-input">
                            <option value="mock" <?= ($tenant['mollie_status'] ?? 'mock') === 'mock' ? 'selected' : '' ?>>Mock (gesimuleerd)</option>
                            <option value="test" <?= ($tenant['mollie_status'] ?? '') === 'test' ? 'selected' : '' ?>>Test (Mollie test omgeving)</option>
                            <option value="live" <?= ($tenant['mollie_status'] ?? '') === 'live' ? 'selected' : '' ?>>Live (echte betalingen)</option>
                        </select>
                        <p id="mollie-mode-status" class="text-sm" style="margin-top:var(--space-xs);text-align:center;"></p>
                    </div>
                    <p class="text-sm text-secondary" style="margin-top: var(--space-sm);">
                        De Mollie Connect koppeling wordt beheerd door de admin in de tenant omgeving (Instellingen → Betalingen).
                    </p>
                </div>

                <!-- Fee Stats -->
                <div style="border-top: 1px solid var(--glass-border); padding-top: var(--space-md); margin-top: var(--space-lg);">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm); color: var(--accent-primary);">Fee Statistieken</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                        <div>
                            <p class="text-sm text-secondary">Vandaag</p>
                            <p style="font-weight: 600; color: #4CAF50;">&euro; <?= number_format($feeStats['today'] / 100, 2, ',', '.') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-secondary">Deze Maand</p>
                            <p style="font-weight: 600; color: #4CAF50;">&euro; <?= number_format($feeStats['this_month'] / 100, 2, ',', '.') ?></p>
                        </div>
                    </div>
                    <?php if ($feeStats['last_invoice_date']): ?>
                        <p class="text-sm text-secondary" style="margin-top: var(--space-sm);">Laatste factuur periode: <?= $feeStats['last_invoice_date'] ?></p>
                    <?php endif; ?>
                    <?php if ($feeStats['next_invoice_date']): ?>
                        <p class="text-sm text-secondary">Volgende factuur: <?= $feeStats['next_invoice_date'] ?></p>
                    <?php endif; ?>
                </div>
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
                                    <button class="btn btn-secondary btn-sm select-user-btn" data-user-id="<?= (int) $u['id'] ?>" data-user-email="<?= sanitize($u['email']) ?>" data-user-role="<?= $u['role'] ?? 'guest' ?>" data-user-name="<?= sanitize(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>" style="padding: 2px 8px; font-size: 12px;">Bewerk</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <!-- User Edit Form (Hidden by default) -->
                <div id="user-edit-panel" class="glass-card" style="padding: var(--space-lg); margin-top: var(--space-md); display: none;">
                    <h3 style="margin-bottom: var(--space-md);" id="user-edit-title">Gebruiker Bewerken</h3>
                    <input type="hidden" id="edit-user-id" value="">
                    <input type="hidden" id="edit-user-role" value="">

                    <!-- E-mail wijziging (alleen voor admin-gebruikers) -->
                    <div id="email-edit-section">
                        <div class="form-group">
                            <label class="text-sm text-secondary">E-mailadres</label>
                            <input type="email" id="edit-user-email" class="form-input" required>
                        </div>
                        <button type="button" id="save-email-btn" class="btn btn-primary" style="width:100%;margin-bottom:var(--space-sm);">E-mail Wijzigen</button>
                        <p id="email-status" class="text-sm" style="margin-bottom:var(--space-md);text-align:center;"></p>
                    </div>

                    <div id="email-edit-notice" style="display:none; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:8px; padding:var(--space-sm); margin-bottom:var(--space-md); font-size:13px; color:#FF9800;">
                        E-mail van bartenders en gasten wordt beheerd door de admin van deze locatie.
                    </div>

                    <hr style="border-color: rgba(255,255,255,0.1); margin: var(--space-md) 0;">

                    <!-- Wachtwoord wijziging (alleen voor admin-gebruikers) -->
                    <div id="password-edit-section">
                        <h4 style="margin-bottom: var(--space-sm); font-size: 14px;">Wachtwoord Wijzigen</h4>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Nieuw Wachtwoord</label>
                            <input type="password" id="edit-new-password" class="form-input" placeholder="Minimaal 8 tekens">
                        </div>
                        <div class="form-group">
                            <label class="text-sm text-secondary">Bevestig Wachtwoord</label>
                            <input type="password" id="edit-confirm-password" class="form-input" placeholder="Bevestig nieuw wachtwoord">
                        </div>
                        <button type="button" id="save-password-btn" class="btn btn-primary" style="width:100%;">Wachtwoord Wijzigen</button>
                        <p id="password-status" class="text-sm" style="margin-top:var(--space-sm);text-align:center;"></p>
                    </div>

                    <hr style="border-color: rgba(255,255,255,0.1); margin: var(--space-md) 0;">

                    <button type="button" id="cancel-edit-user" class="btn btn-secondary" style="width:100%;">Sluiten</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= generateCSRFToken() ?>';
const TENANT_ID = <?= $tenantId ?>;

// View As Admin — start impersonation for this tenant
async function viewAsAdmin(){
    try{
        const res=await fetch((window.__BASE_URL||'')+'/api/superadmin/view-as',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({tenant_id:TENANT_ID})});
        const data=await res.json();
        if(data.success)window.location.href=(window.__BASE_URL||'')+'/admin';
        else alert('Fout: '+(data.error||'Onbekend'));
    }catch(e){alert('Netwerkfout');}
}

// Show/hide test danger zone based on checkbox state
function toggleDangerZone() {
    const cb = document.getElementById('naw-is_test');
    const dz = document.getElementById('test-danger-zone');
    if (cb && dz) {
        dz.style.display = cb.checked ? 'block' : 'none';
    }
}
// Initialize on page load
toggleDangerZone();

// ============================================
// USER EDIT PANEL
// ============================================
const roleLabels = { admin: 'Admin', bartender: 'Bartender', guest: 'Gast', superadmin: 'Superadmin' };

document.querySelectorAll('.select-user-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const userId = this.dataset.userId;
        const userEmail = this.dataset.userEmail;
        const userRole = this.dataset.userRole || 'guest';
        const userName = this.dataset.userName || userEmail;

        // Show the edit panel
        const panel = document.getElementById('user-edit-panel');
        panel.style.display = 'block';
        document.getElementById('edit-user-id').value = userId;
        document.getElementById('edit-user-role').value = userRole;
        document.getElementById('edit-user-email').value = userEmail;
        document.getElementById('edit-new-password').value = '';
        document.getElementById('edit-confirm-password').value = '';
        document.getElementById('email-status').textContent = '';
        document.getElementById('password-status').textContent = '';

        // Show/hide sections based on role — superadmin only edits admin users
        const isAdmin = userRole === 'admin';
        document.getElementById('email-edit-section').style.display = isAdmin ? 'block' : 'none';
        document.getElementById('email-edit-notice').style.display = isAdmin ? 'none' : 'block';
        document.getElementById('password-edit-section').style.display = isAdmin ? 'block' : 'none';

        // Update title
        document.getElementById('user-edit-title').textContent =
            'Bewerk: ' + userName + ' (' + (roleLabels[userRole] || userRole) + ')';

        panel.scrollIntoView({ behavior: 'smooth' });
    });
});

// Cancel / close edit panel
document.getElementById('cancel-edit-user')?.addEventListener('click', function() {
    document.getElementById('user-edit-panel').style.display = 'none';
});

// Save email
document.getElementById('save-email-btn')?.addEventListener('click', async function() {
    const statusEl = document.getElementById('email-status');
    const userId = document.getElementById('edit-user-id').value;
    const newEmail = document.getElementById('edit-user-email').value.trim();

    if (!newEmail) {
        statusEl.textContent = '✗ E-mailadres is verplicht';
        statusEl.style.color = '#f44336';
        return;
    }

    statusEl.textContent = 'E-mail wijzigen...';
    statusEl.style.color = 'var(--text-secondary)';

    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({
                action: 'change_email',
                tenant_id: parseInt(TENANT_ID),
                user_id: parseInt(userId),
                new_email: newEmail
            })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ E-mail gewijzigd';
            statusEl.style.color = '#4CAF50';
            // Update email in the table row
            const row = document.querySelector('tr[data-user-id="' + userId + '"]');
            if (row) {
                const emailCell = row.querySelectorAll('td')[1];
                if (emailCell) emailCell.textContent = newEmail;
                const editBtn = row.querySelector('.select-user-btn');
                if (editBtn) editBtn.dataset.userEmail = newEmail;
            }
        } else {
            statusEl.textContent = '✗ E-mail wijzigen mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
});

// Save password
document.getElementById('save-password-btn')?.addEventListener('click', async function() {
    const statusEl = document.getElementById('password-status');
    const userId = document.getElementById('edit-user-id').value;
    const newPassword = document.getElementById('edit-new-password').value;
    const confirmPassword = document.getElementById('edit-confirm-password').value;

    if (newPassword !== confirmPassword) {
        statusEl.textContent = '✗ Wachtwoorden komen niet overeen';
        statusEl.style.color = '#f44336';
        return;
    }
    if (newPassword.length < 8) {
        statusEl.textContent = '✗ Minimaal 8 tekens vereist';
        statusEl.style.color = '#f44336';
        return;
    }

    statusEl.textContent = 'Wachtwoord wijzigen...';
    statusEl.style.color = 'var(--text-secondary)';

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
            document.getElementById('edit-new-password').value = '';
            document.getElementById('edit-confirm-password').value = '';
        } else {
            statusEl.textContent = '✗ Wachtwoord wijzigen mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
});

// NAW form save
document.getElementById('naw-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const statusEl = document.getElementById('naw-status');
    statusEl.textContent = 'Opslaan...';
    statusEl.style.color = 'var(--text-secondary)';

    const fields = ['name','slug','contact_name','contact_email','phone','address','postal_code','city','country','timezone'];
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
    const verifEl = document.getElementById('naw-verification_required');
    if (verifEl) data.verification_required = verifEl.checked ? 1 : 0;
    const testEl = document.getElementById('naw-is_test');
    if (testEl) data.is_test = testEl.checked ? 1 : 0;

    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ Opgeslagen';
            statusEl.style.color = '#4CAF50';
            // Reload if is_test changed (danger zone needs server render)
            if (data.is_test !== undefined) {
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            statusEl.textContent = '✗ Actie mislukt';
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
document.getElementById('naw-verification_required')?.addEventListener('change', function() {
    document.getElementById('naw-verification_required-label').textContent = this.checked ? 'Verplicht' : 'Uitgeschakeld';
});
// Tenant status toggle label update
document.getElementById('naw-is_active')?.addEventListener('change', function() {
    const label = document.getElementById('naw-is_active-label');
    label.textContent = this.checked ? 'Actief' : 'Uitgeschakeld';
    label.style.color = this.checked ? '#4CAF50' : '#f44336';
});
// Test mode toggle label update
document.getElementById('naw-is_test')?.addEventListener('change', function() {
    const label = document.getElementById('naw-is_test-label');
    label.textContent = this.checked ? 'Test tenant' : 'Productie';
    label.style.color = this.checked ? '#FF9800' : 'inherit';
    toggleDangerZone();
});

// Role change
document.querySelectorAll('.role-select').forEach(sel => {
    sel.addEventListener('change', async function() {
        const userId = this.dataset.userId;
        const newRole = this.value;
        const originalBg = this.style.background;

        this.style.background = 'rgba(255,193,7,0.3)';
        try {
            const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'update_role', user_id: parseInt(userId), role: newRole })
            });
            const result = await res.json();
            if (result.success) {
                this.style.background = 'rgba(76,175,80,0.3)';
                setTimeout(() => { this.style.background = originalBg || ''; }, 1500);
            } else {
                alert('Actie mislukt. Probeer het opnieuw.');
                this.style.background = originalBg || '';
            }
        } catch (err) {
            alert('Er is een netwerkfout opgetreden');
            this.style.background = originalBg || '';
        }
    });
});

// Fee config form save
document.getElementById('fee-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const statusEl = document.getElementById('fee-status');
    statusEl.textContent = 'Opslaan...';
    statusEl.style.color = 'var(--text-secondary)';

    const data = { action: 'update', tenant_id: TENANT_ID };
    data.platform_fee_percentage = parseFloat(document.getElementById('fee-percentage').value);
    data.platform_fee_min_cents = parseInt(document.getElementById('fee-min-cents').value);
    data.invoice_period = document.getElementById('fee-invoice-period').value;
    data.btw_number = document.getElementById('fee-btw-number').value.trim();
    data.invoice_email = document.getElementById('fee-invoice-email').value.trim();
    data.platform_fee_note = document.getElementById('fee-note').value.trim();
    
    // Deposit limits in cents (stored as cents, input is in euros)
    const depositMinEuros = parseFloat(document.getElementById('fee-deposit-min').value);
    const depositMaxEuros = parseFloat(document.getElementById('fee-deposit-max').value);
    if (!isNaN(depositMinEuros) && depositMinEuros >= 1 && depositMinEuros <= 1000) {
        data.deposit_min_cents = Math.round(depositMinEuros * 100);
    }
    if (!isNaN(depositMaxEuros) && depositMaxEuros >= 100 && depositMaxEuros <= 5000) {
        data.deposit_max_cents = Math.round(depositMaxEuros * 100);
    }

    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ Fee config opgeslagen';
            statusEl.style.color = '#4CAF50';
        } else {
            statusEl.textContent = '✗ Actie mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 3000);
});

// Mollie mode change — save via superadmin tenants API
document.getElementById('mollie-mode-select')?.addEventListener('change', async function() {
    const statusEl = document.getElementById('mollie-mode-status');
    const newMode = this.value;
    statusEl.textContent = 'Opslaan...';
    statusEl.style.color = 'var(--text-secondary)';
    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'update', tenant_id: TENANT_ID, mollie_status: newMode })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ Opgeslagen';
            statusEl.style.color = '#4CAF50';
        } else {
            statusEl.textContent = '✗ Actie mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 3000);
});

// === TEST TENANT PURGE ACTIONS ===

document.getElementById('btn-purge-guests')?.addEventListener('click', async () => {
    if (!confirm('⚠ Alle gasten voor deze tenant PERMANENT verwijderen?\n\nDit verwijdert ook hun wallets, transacties en notificaties.')) return;
    const statusEl = document.getElementById('purge-status');
    statusEl.textContent = 'Gasten verwijderen...';
    statusEl.style.color = 'var(--text-secondary)';
    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'purge_guests', tenant_id: TENANT_ID })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ ' + (result.purged_guests || 0) + ' gasten en ' + (result.purged_transactions || 0) + ' transacties verwijderd';
            statusEl.style.color = '#4CAF50';
            setTimeout(() => location.reload(), 2000);
        } else {
            statusEl.textContent = '✗ Actie mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 6000);
});

document.getElementById('btn-purge-packages')?.addEventListener('click', async () => {
    if (!confirm('⚠ Alle pakketten (loyalty tiers) voor deze tenant PERMANENT verwijderen?')) return;
    const statusEl = document.getElementById('purge-status');
    statusEl.textContent = 'Pakketten verwijderen...';
    statusEl.style.color = 'var(--text-secondary)';
    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'purge_packages', tenant_id: TENANT_ID })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ ' + (result.purged_packages || 0) + ' pakketten verwijderd';
            statusEl.style.color = '#4CAF50';
            setTimeout(() => location.reload(), 2000);
        } else {
            statusEl.textContent = '✗ Actie mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 6000);
});

document.getElementById('btn-reset-balances')?.addEventListener('click', async () => {
    if (!confirm('⚠ Saldo van alle gasten naar €0.00 resetten?\n\nDe gasten blijven bestaan, maar hun wallet wordt geleegd.')) return;
    const statusEl = document.getElementById('purge-status');
    statusEl.textContent = 'Saldo resetten...';
    statusEl.style.color = 'var(--text-secondary)';
    try {
        const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/tenants', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'reset_guest_balances', tenant_id: TENANT_ID })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = '✓ ' + (result.wallets_reset || 0) + ' wallets gereset naar €0.00';
            statusEl.style.color = '#4CAF50';
            setTimeout(() => location.reload(), 2000);
        } else {
            statusEl.textContent = '✗ Actie mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = '✗ Netwerkfout';
        statusEl.style.color = '#f44336';
    }
    setTimeout(() => { statusEl.textContent = ''; }, 6000);
});

// ── Mollie Connect account status: live refresh ──────────────────────────
// Haalt de actuele onboarding-status op via /api/superadmin/mollie-status,
// werkt de badge bij en cached het resultaat server-side.
(function () {
    const refreshBtn = document.getElementById('mollie-acct-refresh');
    if (!refreshBtn) return;

    const badge   = document.getElementById('mollie-acct-badge');
    const checked = document.getElementById('mollie-acct-checked');
    const detail  = document.getElementById('mollie-acct-detail');

    function applyStatus(status, canReceive) {
        let color, label;
        if (canReceive === true && status === 'completed') {
            color = 'rgba(76,175,80,0.2);color:#4CAF50'; label = 'Live';
        } else if (status === 'in-review') {
            color = 'rgba(255,152,0,0.2);color:#FF9800'; label = 'In behandeling';
        } else if (status === 'blocked') {
            color = 'rgba(244,67,54,0.2);color:#f44336'; label = 'Geblokkeerd';
        } else if (status === 'needs-data' || (status && canReceive === false)) {
            color = 'rgba(244,67,54,0.2);color:#f44336'; label = 'Onvolledig';
        } else {
            color = 'rgba(158,158,158,0.2);color:#9e9e9e'; label = 'Onbekend';
        }
        badge.style.cssText = 'background:' + color + ';';
        badge.textContent = label;
        badge.setAttribute('data-status', status || '');
        badge.setAttribute('data-can-receive', canReceive === null ? '' : (canReceive ? '1' : '0'));
    }

    refreshBtn.addEventListener('click', async () => {
        const originalText = refreshBtn.textContent;
        refreshBtn.disabled = true;
        refreshBtn.textContent = 'Controleren...';
        detail.textContent = '';
        detail.style.color = '';

        try {
            const res = await fetch((window.__BASE_URL || '') + '/api/superadmin/mollie-status?tenant_id=' + TENANT_ID, {
                headers: { 'X-CSRF-Token': CSRF }
            });
            const result = await res.json();

            if (!result.success) {
                throw new Error(result.error || 'Onbekende fout');
            }

            const data = result.data || {};
            const acct = data.account_status;
            const cached = data.cached || {};

            if (data.error) {
                // API call itself reported an error (bijv. token probleem)
                detail.textContent = '⚠ ' + data.error;
                detail.style.color = '#f44336';
                refreshBtn.textContent = originalText;
                refreshBtn.disabled = false;
                return;
            }

            // Primary: unified account_status (derived from profiles + optional onboarding)
            if (acct) {
                applyStatus(acct.status, acct.can_receive_payments);
            } else if (cached.onboarding_status !== null && cached.onboarding_status !== undefined) {
                applyStatus(cached.onboarding_status, cached.can_receive_payments);
            }

            // Toon "laatst gecontroleerd" — cached.checked_at is UTC ISO
            if (cached.checked_at) {
                let label = cached.checked_at;
                try {
                    const dt = new Date(cached.checked_at + 'Z');
                    label = dt.toLocaleString('nl-NL', {
                        day: 'numeric', month: 'short', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                } catch (e) { /* fallback raw */ }
                checked.textContent = 'Laatst gecontroleerd: ' + label;
            }

            detail.textContent = '✓ Status vernieuwd';
            detail.style.color = '#4CAF50';
        } catch (err) {
            detail.textContent = '✗ ' + (err.message || 'Netwerkfout');
            detail.style.color = '#f44336';
        } finally {
            refreshBtn.textContent = originalText;
            refreshBtn.disabled = false;
            setTimeout(() => { if (detail.textContent.startsWith('✓')) detail.textContent = ''; }, 6000);
        }
    });
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
