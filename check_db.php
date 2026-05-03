<?php
declare(strict_types=1);

/**
 * REGULR.vip — Database Migration Check Script
 * 
 * Upload dit bestand naar de root van je Hostinger installatie
 * en open het in je browser: https://app.regulr.vip/check_db.php
 * 
 * Het controleert of alle vereiste tabellen en kolommen bestaan.
 * VERWIJDER dit bestand na gebruik!
 */

// Load config
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo '<html><head><title>REGULR.vip — DB Check</title>';
echo '<style>body{font-family:monospace;max-width:900px;margin:2em auto;padding:0 1em}';
echo '.ok{color:green} .fail{color:red} .warn{color:orange}';
echo 'table{border-collapse:collapse;width:100%} td,th{border:1px solid #ddd;padding:6px 10px;text-align:left}';
echo 'th{background:#f5f5f5} h2{margin-top:2em}</style></head><body>';

echo '<h1>REGULR.vip — Database Migratie Check</h1>';
echo '<p>Draait op: ' . APP_ENV . ' | PHP ' . PHP_VERSION . '</p>';

try {
    $db = Database::getInstance()->getConnection();
    echo '<p class="ok">Database connectie: OK</p>';
} catch (\Throwable $e) {
    echo '<p class="fail">Database connectie GEFAALD: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Controleer je .env bestand met DB credentials.</p>';
    echo '</body></html>';
    exit;
}

// ── Vereiste tabellen ──
echo '<h2>1. Vereiste Tabellen</h2>';

$requiredTables = [
    'tenants'              => 'Basis: tenants',
    'users'                => 'Basis: users',
    'wallets'              => 'Basis: wallets',
    'transactions'         => 'Basis: transactions',
    'loyalty_tiers'        => 'Basis: loyalty_tiers',
    'audit_log'            => 'Basis: audit_log',
    'email_queue'          => 'Basis: email_queue',
    'push_subscriptions'   => 'Basis: push_subscriptions',
    'email_config'         => 'Migratie: email_system',
    'email_templates'      => 'Migratie: email_system',
    'email_log'            => 'Migratie: email_system',
    'platform_settings'    => 'Migratie: platform_settings',
    'platform_invoices'    => 'Migratie: platform_fee',
    'platform_fees'        => 'Migratie: platform_fee',
    'platform_fee_log'     => 'Migratie: platform_fee',
    'notifications'        => 'Migratie: notifications',
    'verification_attempts' => 'Migratie: gated_onboarding',
];

$stmt = $db->query("SHOW TABLES");
$existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo '<table><tr><th>Tabel</th><th>Bron</th><th>Status</th></tr>';
$missingTables = 0;
foreach ($requiredTables as $table => $source) {
    $exists = in_array($table, $existingTables);
    if (!$exists) $missingTables++;
    echo '<tr>';
    echo '<td>' . htmlspecialchars($table) . '</td>';
    echo '<td>' . htmlspecialchars($source) . '</td>';
    echo '<td class="' . ($exists ? 'ok' : 'fail') . '">' . ($exists ? 'OK' : 'ONTBREEKT') . '</td>';
    echo '</tr>';
}
echo '</table>';

// ── Vereiste kolommen in tenants ──
echo '<h2>2. Kolommen in `tenants`</h2>';

$requiredTenantColumns = [
    // Basis schema
    'id', 'uuid', 'name', 'slug', 'brand_color', 'secondary_color', 'logo_path',
    'secret_key', 'mollie_api_key', 'mollie_status', 'whitelisted_ips',
    'contact_name', 'contact_email', 'phone', 'address', 'postal_code', 'city', 'country',
    'is_active', 'feature_push', 'feature_marketing', 'created_at', 'updated_at',
    // platform_fee_migration
    'platform_fee_percentage', 'platform_fee_min_cents',
    'mollie_connect_id', 'mollie_connect_status',
    'invoice_period', 'btw_number', 'invoice_email', 'platform_fee_note',
    // gated_onboarding_migration
    'verification_soft_limit', 'verification_hard_limit',
    'verification_cooldown_sec', 'verification_max_attempts',
    // verification_toggle_migration
    'verification_required',
];

$stmt = $db->query("SHOW COLUMNS FROM `tenants`");
$existingTenantCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo '<table><tr><th>Kolom</th><th>Status</th></tr>';
$missingTenantCols = 0;
foreach ($requiredTenantColumns as $col) {
    $exists = in_array($col, $existingTenantCols);
    if (!$exists) $missingTenantCols++;
    echo '<tr>';
    echo '<td>' . htmlspecialchars($col) . '</td>';
    echo '<td class="' . ($exists ? 'ok' : 'fail') . '">' . ($exists ? 'OK' : 'ONTBREEKT') . '</td>';
    echo '</tr>';
}
echo '</table>';

// ── Vereiste kolommen in users ──
echo '<h2>3. Kolommen in `users`</h2>';

$requiredUserColumns = [
    // Basis schema
    'id', 'tenant_id', 'email', 'password_hash', 'role',
    'first_name', 'last_name', 'birthdate', 'photo_url', 'photo_status',
    'push_token', 'last_activity', 'created_at', 'updated_at',
    // gated_onboarding_migration
    'account_status', 'verified_at', 'verified_by', 'verified_birthdate',
    'suspended_reason', 'suspended_at', 'suspended_by',
];

$stmt = $db->query("SHOW COLUMNS FROM `users`");
$existingUserCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo '<table><tr><th>Kolom</th><th>Status</th></tr>';
$missingUserCols = 0;
foreach ($requiredUserColumns as $col) {
    $exists = in_array($col, $existingUserCols);
    if (!$exists) $missingUserCols++;
    echo '<tr>';
    echo '<td>' . htmlspecialchars($col) . '</td>';
    echo '<td class="' . ($exists ? 'ok' : 'fail') . '">' . ($exists ? 'OK' : 'ONTBREEKT') . '</td>';
    echo '</tr>';
}
echo '</table>';

// ── Samenvatting ──
echo '<h2>4. Samenvatting</h2>';

$totalIssues = $missingTables + $missingTenantCols + $missingUserCols;

if ($totalIssues === 0) {
    echo '<p class="ok"><strong>Alle checks geslaagd!</strong> De database is volledig gemigreerd.</p>';
    echo '<p>Je kunt nu tenants aanmaken via de superadmin interface.</p>';
} else {
    echo '<p class="fail"><strong>' . $totalIssues . ' probleem(en) gevonden:</strong></p>';
    echo '<ul>';
    if ($missingTables > 0) echo '<li class="fail">' . $missingTables . ' tabel(len) ontbreken</li>';
    if ($missingTenantCols > 0) echo '<li class="fail">' . $missingTenantCols . ' kolom(men) ontbreken in `tenants`</li>';
    if ($missingUserCols > 0) echo '<li class="fail">' . $missingUserCols . ' kolom(men) ontbreken in `users`</li>';
    echo '</ul>';
    
    echo '<h3>Oplossing</h3>';
    echo '<p>Voer de ontbrekende migraties uit via phpMyAdmin of door <code>deploy.php</code> opnieuw te draaien.</p>';
    echo '<p>De migratie SQL bestanden staan in de <code>sql/</code> directory:</p>';
    echo '<ol>';
    echo '<li><code>sql/platform_fee_migration.sql</code> — Voegt platform fee kolommen toe aan tenants + maakt platform_fees tabellen</li>';
    echo '<li><code>sql/gated_onboarding_migration.sql</code> — Voegt account_status kolommen toe aan users + maakt verification_attempts tabel</li>';
    echo '<li><code>sql/verification_toggle_migration.sql</code> — Voegt verification_required toe aan tenants</li>';
    echo '<li><code>sql/email_system_migration.sql</code> — Maakt email_config, email_templates, email_log tabellen</li>';
    echo '<li><code>sql/notifications_migration.sql</code> — Maakt notifications tabel</li>';
    echo '<li><code>sql/platform_settings_migration.sql</code> — Maakt platform_settings tabel</li>';
    echo '</ol>';
    echo '<p><strong>Tip:</strong> Je kunt <code>deploy.php</code> veilig opnieuw draaien — het slaat over wat al bestaat.</p>';
}

echo '<hr>';
echo '<p style="color:red"><strong>VERWIJDER dit bestand (check_db.php) na gebruik!</strong></p>';
echo '</body></html>';
