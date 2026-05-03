<?php
declare(strict_types=1);

/**
 * REGULR.vip — Web Migration Runner
 * 
 * Access via browser: https://app.regulr.vip/superadmin/migrate
 * Protected by superadmin authentication.
 * 
 * Safe to run multiple times — skips already-applied migrations.
 */

// ── Helper functions ────────────────────────────────────────────────────────

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = :table"
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.columns 
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column"
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Execute a migration SQL file.
 * Handles individual ALTER TABLE failures gracefully (idempotent).
 */
function runMigrationFile(PDO $db, string $filePath): array
{
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        return ['success' => false, 'error' => 'File not found: ' . $filePath, 'errors' => [], 'statements' => 0];
    }

    $errors = [];
    $statementsRun = 0;

    // Split on semicolons followed by newline
    $statements = array_filter(
        array_map('trim', explode(";\n", $sql)),
        fn(string $s) => !empty($s) && !str_starts_with($s, '--') && $s !== ';'
    );

    foreach ($statements as $statement) {
        $statement = rtrim($statement, ';');
        if (empty($statement)) continue;
        if (preg_match('/^\s*--/', $statement)) continue;

        try {
            $db->exec($statement);
            $statementsRun++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();

            // Idempotency: ignore "already exists" errors
            if (
                str_contains($errorMsg, 'already exists') ||
                str_contains($errorMsg, 'Duplicate column') ||
                str_contains($errorMsg, 'Duplicate key') ||
                str_contains($errorMsg, 'multiple primary key')
            ) {
                continue;
            }

            $errors[] = $errorMsg . "\n  SQL: " . mb_substr($statement, 0, 150) . '...';
        }
    }

    return [
        'success'    => empty($errors),
        'errors'     => $errors,
        'statements' => $statementsRun,
    ];
}

// ── Migration definitions ───────────────────────────────────────────────────

function getMigrations(): array
{
    return [
        [
            'name'  => 'Base Schema (tenants, users, wallets, etc.)',
            'file'  => 'schema.sql',
            'check' => fn(PDO $db): bool => tableExists($db, 'tenants'),
        ],
        [
            'name'  => 'Notifications Table',
            'file'  => 'notifications_migration.sql',
            'check' => fn(PDO $db): bool => tableExists($db, 'notifications'),
        ],
        [
            'name'  => 'Package Tiers Columns (loyalty_tiers)',
            'file'  => 'package_tiers_migration.sql',
            'check' => fn(PDO $db): bool => columnExists($db, 'loyalty_tiers', 'topup_amount_cents'),
        ],
        [
            'name'  => 'Transactions created_at Column',
            'file'  => 'add_created_at_column.sql',
            'check' => fn(PDO $db): bool => columnExists($db, 'transactions', 'created_at'),
        ],
        [
            'name'  => 'Platform Fee System (fees, invoices, tenant columns)',
            'file'  => 'platform_fee_migration.sql',
            'check' => fn(PDO $db): bool => columnExists($db, 'tenants', 'platform_fee_percentage')
                && tableExists($db, 'platform_fees'),
        ],
        [
            'name'  => 'Email System (config, templates, log)',
            'file'  => 'email_system_migration.sql',
            'check' => fn(PDO $db): bool => tableExists($db, 'email_config')
                && tableExists($db, 'email_templates'),
        ],
        [
            'name'  => 'Platform Settings Table',
            'file'  => 'platform_settings_migration.sql',
            'check' => fn(PDO $db): bool => tableExists($db, 'platform_settings'),
        ],
        [
            'name'  => 'Gated Onboarding KYC-light (account_status, verification)',
            'file'  => 'gated_onboarding_migration.sql',
            'check' => fn(PDO $db): bool => columnExists($db, 'users', 'account_status')
                && tableExists($db, 'verification_attempts'),
        ],
        [
            'name'  => 'Verification Toggle (verification_required)',
            'file'  => 'verification_toggle_migration.sql',
            'check' => fn(PDO $db): bool => columnExists($db, 'tenants', 'verification_required'),
        ],
    ];
}

// ── Main logic ──────────────────────────────────────────────────────────────

$db = Database::getInstance()->getConnection();
$migrations = getMigrations();
$sqlPath = ROOT_PATH . 'sql/';

$runMode = ($_POST['action'] ?? '') === 'run';
$results = [];

// If user clicked "Run Migrations", execute all pending ones
if ($runMode) {
    foreach ($migrations as &$migration) {
        $isApplied = ($migration['check'])($db);
        $migration['applied'] = $isApplied;
        $migration['result'] = null;

        if ($isApplied) continue;

        $fullPath = $sqlPath . $migration['file'];

        if (!file_exists($fullPath)) {
            $migration['result'] = [
                'success' => false,
                'error'   => 'File missing: ' . $migration['file'],
                'statements' => 0,
            ];
            continue;
        }

        $result = runMigrationFile($db, $fullPath);
        $migration['result'] = $result;

        // Re-check after running
        $migration['applied'] = ($migration['check'])($db);
    }
    unset($migration);
} else {
    // Just check status
    foreach ($migrations as &$migration) {
        $migration['applied'] = ($migration['check'])($db);
        $migration['result'] = null;
    }
    unset($migration);
}

// ── Build verification data ─────────────────────────────────────────────────

$requiredTables = [
    'tenants', 'users', 'wallets', 'loyalty_tiers', 'transactions',
    'push_subscriptions', 'email_queue', 'audit_log',
    'notifications', 'email_config', 'email_templates', 'email_log',
    'platform_fees', 'platform_invoices', 'platform_fee_log',
    'platform_settings', 'verification_attempts',
];

$requiredColumns = [
    'tenants' => [
        'uuid', 'name', 'slug', 'brand_color', 'secondary_color', 'secret_key',
        'mollie_status', 'whitelisted_ips', 'is_active', 'feature_push',
        'feature_marketing', 'contact_name', 'contact_email', 'phone',
        'address', 'postal_code', 'city', 'country',
        'platform_fee_percentage', 'platform_fee_min_cents',
        'mollie_connect_status', 'invoice_period', 'btw_number',
        'invoice_email', 'platform_fee_note',
        'verification_soft_limit', 'verification_hard_limit',
        'verification_cooldown_sec', 'verification_max_attempts',
        'verification_required',
    ],
    'users' => [
        'tenant_id', 'email', 'password_hash', 'role', 'first_name', 'last_name',
        'birthdate', 'photo_url', 'photo_status', 'push_token',
        'account_status', 'verified_at', 'verified_by',
        'verified_birthdate', 'suspended_reason', 'suspended_at', 'suspended_by',
    ],
    'loyalty_tiers' => [
        'id', 'tenant_id', 'name', 'min_deposit_cents',
        'topup_amount_cents', 'alcohol_discount_perc', 'food_discount_perc',
        'points_multiplier', 'is_active', 'sort_order',
    ],
    'transactions' => [
        'id', 'tenant_id', 'user_id', 'type', 'final_total_cents', 'created_at',
    ],
];

$tableChecks = [];
foreach ($requiredTables as $table) {
    $tableChecks[$table] = tableExists($db, $table);
}

$columnChecks = [];
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnChecks[$table][$column] = columnExists($db, $table, $column);
    }
}

$allTablesOk = !in_array(false, $tableChecks, true);
$allColumnsOk = true;
foreach ($columnChecks as $cols) {
    if (in_array(false, $cols, true)) {
        $allColumnsOk = false;
        break;
    }
}
$allOk = $allTablesOk && $allColumnsOk;

// Count migration status
$appliedCount = count(array_filter($migrations, fn($m) => $m['applied']));
$pendingCount = count($migrations) - $appliedCount;

// ── Render HTML ─────────────────────────────────────────────────────────────
require VIEWS_PATH . 'shared/header.php';
?>

<div class="container" style="padding: var(--space-lg); max-width: 1000px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>🗄️ Database Migraties</h1>
        <a href="<?= BASE_URL ?>/superadmin/settings" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Status Summary -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); text-align: center;">
        <div style="display: flex; justify-content: center; gap: 3rem; font-size: 1.1rem;">
            <div>
                <div style="font-size: 2rem; font-weight: 700; color: #4CAF50;"><?= $appliedCount ?></div>
                <div style="opacity: 0.7;">Toegepast</div>
            </div>
            <div>
                <div style="font-size: 2rem; font-weight: 700; color: <?= $pendingCount > 0 ? '#FFC107' : '#4CAF50' ?>;"><?= $pendingCount ?></div>
                <div style="opacity: 0.7;">Openstaand</div>
            </div>
            <div>
                <div style="font-size: 2rem; font-weight: 700; color: <?= $allOk ? '#4CAF50' : '#f44336' ?>;">
                    <?= $allOk ? '✓' : '✗' ?>
                </div>
                <div style="opacity: 0.7;">Schema</div>
            </div>
        </div>
    </div>

    <?php if ($allOk && $pendingCount === 0): ?>
        <div class="glass-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
            <h2 style="color: #4CAF50;">Database schema is volledig up-to-date</h2>
            <p style="opacity: 0.7;">Alle <?= count($migrations) ?> migraties zijn toegepast en geverifieerd.</p>
        </div>
    <?php endif; ?>

    <?php if ($pendingCount > 0): ?>
        <!-- Run Button -->
        <form method="POST" style="margin-bottom: var(--space-lg);">
            <input type="hidden" name="action" value="run">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px;"
                onclick="this.textContent='⏳ Migraties draaien...'; this.disabled=true; this.form.submit();">
                ▶ Run <?= $pendingCount ?> openstaande migratie(s)
            </button>
        </form>
    <?php endif; ?>

    <!-- Migration Details -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Migraties</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <th style="text-align: left; padding: 8px;">#</th>
                    <th style="text-align: left; padding: 8px;">Migratie</th>
                    <th style="text-align: left; padding: 8px;">Bestand</th>
                    <th style="text-align: center; padding: 8px;">Status</th>
                    <?php if ($runMode): ?>
                        <th style="text-align: left; padding: 8px;">Resultaat</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($migrations as $i => $m): ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <td style="padding: 8px; opacity: 0.5;"><?= $i + 1 ?></td>
                    <td style="padding: 8px;"><?= sanitize($m['name']) ?></td>
                    <td style="padding: 8px;"><code style="font-size: 12px; opacity: 0.6;"><?= sanitize($m['file']) ?></code></td>
                    <td style="padding: 8px; text-align: center;">
                        <?php if ($m['applied']): ?>
                            <span style="color: #4CAF50;">✓ Toegepast</span>
                        <?php elseif ($m['result'] && $m['result']['success'] && $m['applied']): ?>
                            <span style="color: #4CAF50;">✓ Net toegepast</span>
                        <?php elseif ($m['result'] && !$m['result']['success']): ?>
                            <span style="color: #f44336;">✗ Gefaald</span>
                        <?php else: ?>
                            <span style="color: #FFC107;">⏳ Openstaand</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($runMode): ?>
                        <td style="padding: 8px; font-size: 13px;">
                            <?php if ($m['result']): ?>
                                <?php if ($m['result']['success']): ?>
                                    <span style="color: #4CAF50;"><?= $m['result']['statements'] ?> statements uitgevoerd</span>
                                <?php else: ?>
                                    <span style="color: #f44336;">
                                        <?php if (!empty($m['result']['error'])): ?>
                                            <?= sanitize($m['result']['error']) ?>
                                        <?php else: ?>
                                            <?php foreach ($m['result']['errors'] as $err): ?>
                                                <?= sanitize(mb_substr($err, 0, 200)) ?><br>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($m['applied'] && !$m['result']): ?>
                                <span style="opacity: 0.4;">Overgeslagen (was al toegepast)</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Schema Verification -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Schema Verificatie</h2>

        <h3 style="font-size: 14px; opacity: 0.7; margin-bottom: 8px;">Tabellen (<?= count($requiredTables) ?>)</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 4px; margin-bottom: var(--space-md);">
            <?php foreach ($tableChecks as $table => $exists): ?>
                <span style="font-size: 13px; padding: 4px 8px; border-radius: 4px; background: <?= $exists ? 'rgba(76,175,80,0.15)' : 'rgba(244,67,54,0.15)' ?>; color: <?= $exists ? '#4CAF50' : '#f44336' ?>;">
                    <?= $exists ? '✓' : '✗' ?> <?= sanitize($table) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <h3 style="font-size: 14px; opacity: 0.7; margin-bottom: 8px;">Kritieke Kolommen</h3>
        <?php foreach ($columnChecks as $table => $columns): ?>
            <div style="margin-bottom: 12px;">
                <div style="font-size: 12px; opacity: 0.5; margin-bottom: 4px;"><?= sanitize($table) ?></div>
                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                    <?php foreach ($columns as $col => $exists): ?>
                        <span style="font-size: 11px; padding: 2px 6px; border-radius: 3px; background: <?= $exists ? 'rgba(76,175,80,0.1)' : 'rgba(244,67,54,0.1)' ?>; color: <?= $exists ? '#4CAF50' : '#f44336' ?>;">
                            <?= $exists ? '✓' : '✗' ?> <?= sanitize($col) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Refresh -->
    <div style="text-align: center;">
        <a href="<?= BASE_URL ?>/superadmin/migrate" class="btn btn-secondary" style="opacity: 0.7;">
            🔄 Opnieuw controleren
        </a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php';
