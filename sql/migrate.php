<?php
declare(strict_types=1);

/**
 * REGULR.vip — Database Migration Runner
 * 
 * Runs all pending SQL migrations in the correct order and verifies the result.
 * 
 * Usage:
 *   Local:    php sql/migrate.php
 *   Production: php sql/migrate.php --env=production
 * 
 * Safe to run multiple times — skips already-applied migrations.
 * Uses column/table existence checks instead of a migrations table
 * for maximum compatibility with existing deployments.
 */

// ── Prevent web access ──────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && !defined('REGULR_MIGRATE_ALLOW_WEB')) {
    http_response_code(403);
    die("This script can only be run from CLI.\n");
}

// ── Color helpers for CLI output (overridable by web_migrate.php) ───────────
if (!function_exists('green'))   { function green(string $text): string   { return "\033[32m{$text}\033[0m"; } }
if (!function_exists('red'))     { function red(string $text): string     { return "\033[31m{$text}\033[0m"; } }
if (!function_exists('yellow'))  { function yellow(string $text): string  { return "\033[33m{$text}\033[0m"; } }
if (!function_exists('bold'))    { function bold(string $text): string    { return "\033[1m{$text}\033[0m"; } }
if (!function_exists('dim'))     { function dim(string $text): string     { return "\033[2m{$text}\033[0m"; } }

// ── Load environment ────────────────────────────────────────────────────────
$rootPath = dirname(__DIR__);
$envFile  = $rootPath . '/.env';

// Check for --env=production flag
$isProduction = false;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--env=production' || $arg === '--prod') {
        $isProduction = true;
    }
}

// Load .env manually (simple parser, no external deps)
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eqPos = strpos($line, '=');
        if ($eqPos !== false) {
            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));
            // Only set if not already defined (CLI args take precedence)
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
            }
        }
    }
}

// ── Database connection ─────────────────────────────────────────────────────
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'stamgast_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

echo bold("\n╔══════════════════════════════════════════════════════════╗\n");
echo bold("║        REGULR.vip — Database Migration Runner           ║\n");
echo bold("╚══════════════════════════════════════════════════════════╝\n\n");

echo "Environment: " . ($isProduction ? yellow('PRODUCTION') : green('DEVELOPMENT')) . "\n";
echo "Database:    {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n\n";

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    echo red("✗ Database connection failed: " . $e->getMessage()) . "\n";
    echo dim("  Check your .env file or DB_* environment variables.\n\n");
    exit(1);
}

echo green("✓ Database connection successful\n\n");

// ── Migration definitions (in correct dependency order) ─────────────────────
$migrations = [
    [
        'name'   => 'Base Schema',
        'file'   => 'schema.sql',
        'type'   => 'schema',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'tenants');
        },
    ],
    [
        'name'   => 'Password Reset Tokens',
        'file'   => 'password_reset_migration.sql',
        'type'   => 'table',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'password_resets');
        },
    ],
    [
        'name'   => 'Notifications Table',
        'file'   => 'notifications_migration.sql',
        'type'   => 'table',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'notifications');
        },
    ],
    [
        'name'   => 'Package Tiers Columns',
        'file'   => 'package_tiers_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'loyalty_tiers', 'topup_amount_cents');
        },
    ],
    [
        'name'   => 'Transactions created_at Column',
        'file'   => 'add_created_at_column.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'transactions', 'created_at');
        },
    ],
    [
        'name'   => 'Platform Fee System',
        'file'   => 'platform_fee_migration.sql',
        'type'   => 'alter+table',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'platform_fee_percentage')
                && tableExists($db, 'platform_fees');
        },
    ],
    [
        'name'   => 'Email System (config, templates, log)',
        'file'   => 'email_system_migration.sql',
        'type'   => 'table',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'email_config')
                && tableExists($db, 'email_templates')
                && tableExists($db, 'email_log');
        },
    ],
    [
        'name'   => 'Platform Settings',
        'file'   => 'platform_settings_migration.sql',
        'type'   => 'table',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'platform_settings');
        },
    ],
    [
        'name'   => 'Gated Onboarding (KYC-light)',
        'file'   => 'gated_onboarding_migration.sql',
        'type'   => 'alter+table',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'users', 'account_status')
                && tableExists($db, 'verification_attempts');
        },
    ],
    [
        'name'   => 'Verification Toggle',
        'file'   => 'verification_toggle_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'verification_required');
        },
    ],
    [
        'name'   => 'Points Toggle',
        'file'   => 'points_toggle_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'points_enabled');
        },
    ],
    [
        'name'   => 'Tier Model Type (discount/bonus)',
        'file'   => 'model_type_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'loyalty_tiers', 'model_type');
        },
    ],
    [
        'name'   => 'WebAuthn Credentials & Challenges',
        'file'   => 'webauthn_migration.sql',
        'type'   => 'table',
        'check'  => function (PDO $db): bool {
            return tableExists($db, 'user_credentials')
                && tableExists($db, 'webauthn_challenges');
        },
    ],
    [
        'name'   => 'FCM Token & Profile Columns',
        'type'   => 'inline',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'users', 'fcm_token')
                && columnExists($db, 'users', 'phone')
                && columnExists($db, 'users', 'street');
        },
        'run'    => function (PDO $db): bool {
            $cols = [
                ['fcm_token',      "ALTER TABLE `users` ADD COLUMN `fcm_token` TEXT NULL AFTER `push_token`"],
                ['phone',          "ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL"],
                ['address',        "ALTER TABLE `users` ADD COLUMN `address` TEXT NULL DEFAULT NULL"],
                ['street',         "ALTER TABLE `users` ADD COLUMN `street` VARCHAR(100) NULL DEFAULT NULL"],
                ['house_number',   "ALTER TABLE `users` ADD COLUMN `house_number` VARCHAR(10) NULL DEFAULT NULL"],
                ['postal_code',    "ALTER TABLE `users` ADD COLUMN `postal_code` VARCHAR(10) NULL DEFAULT NULL"],
                ['city',           "ALTER TABLE `users` ADD COLUMN `city` VARCHAR(50) NULL DEFAULT NULL"],
            ];
            foreach ($cols as [$col, $sql]) {
                if (!columnExists($db, 'users', $col)) {
                    $db->exec($sql);
                }
            }
            return true;
        },
    ],
];

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
 * Execute a migration SQL file using multi-query execution.
 * Handles individual ALTER TABLE failures gracefully (idempotent).
 */
function runMigrationFile(PDO $db, string $filePath): array
{
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        return ['success' => false, 'error' => 'File not found: ' . $filePath];
    }

    $errors = [];
    $statementsRun = 0;

    // Remove comment lines (-- ...) and split on semicolons
    // This handles multi-line CREATE TABLE statements correctly
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Skip empty lines and comment-only lines
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Split on semicolons followed by newline (or end of string)
    $statements = explode(";\n", $cleanSql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        $statement = rtrim($statement, ';');
        if (empty($statement)) continue;

        try {
            $db->exec($statement);
            $statementsRun++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            
            // Idempotency: ignore "already exists" errors
            if (
                str_contains($errorMsg, 'already exists') ||
                str_contains($errorMsg, 'Duplicate') ||
                str_contains($errorMsg, 'Duplicate column') ||
                str_contains($errorMsg, 'Duplicate key') ||
                str_contains($errorMsg, 'multiple primary key')
            ) {
                // Already applied — not an error
                continue;
            }
            
            $errors[] = $errorMsg . "\n  SQL: " . mb_substr($statement, 0, 120) . '...';
        }
    }

    return [
        'success'       => empty($errors),
        'errors'        => $errors,
        'statements'    => $statementsRun,
    ];
}

// ── Phase 1: Check current state ────────────────────────────────────────────
echo bold("── Phase 1: Checking migration status ─────────────────────\n\n");

$applied = 0;
$pending = 0;
$skipped = 0;

foreach ($migrations as $i => &$migration) {
    $isApplied = ($migration['check'])($db);
    $migration['applied'] = $isApplied;
    
    $num = str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT);
    if ($isApplied) {
        echo "  {$num}. " . green('✓') . " {$migration['name']} " . dim('[already applied]') . "\n";
        $applied++;
    } else {
        echo "  {$num}. " . yellow('⏳') . " {$migration['name']} " . yellow('[PENDING]') . "\n";
        $pending++;
    }
}
unset($migration);

echo "\n  Applied: {$applied} | Pending: {$pending} | Total: " . count($migrations) . "\n\n";

if ($pending === 0) {
    echo green("✓ All migrations are up to date. Nothing to do.\n\n");
    
    // Still run the verification phase
    runVerification($db);
    exit(0);
}

// ── Phase 2: Run pending migrations ────────────────────────────────────────
echo bold("── Phase 2: Running pending migrations ────────────────────\n\n");

$failed = 0;

foreach ($migrations as $i => $migration) {
    if ($migration['applied']) {
        continue;
    }

    $num = $i + 1;

    // Inline migration (no SQL file, uses callable)
    if (($migration['type'] ?? '') === 'inline' && isset($migration['run'])) {
        echo "  {$num}. Running {$migration['name']}...";
        try {
            $ok = ($migration['run'])($db);
            $verifyOk = ($migration['check'])($db);
            if ($ok && $verifyOk) {
                echo green(" ✓ OK") . "\n";
            } else {
                echo yellow(" ⚠ RAN but check still fails\n");
                $failed++;
            }
        } catch (\Throwable $e) {
            echo red(" ✗ FAILED: " . $e->getMessage()) . "\n";
            $failed++;
        }
        continue;
    }

    $file = $migration['file'];
    $fullPath = __DIR__ . '/' . $file;
    
    echo "  {$num}. Running {$migration['name']}...";
    
    if (!file_exists($fullPath)) {
        echo red(" ✗ FILE MISSING: {$file}\n") . dim("     Create the file or remove the migration entry.\n");
        $failed++;
        continue;
    }

    $result = runMigrationFile($db, $fullPath);

    if ($result['success']) {
        // Verify the migration actually applied
        $verifyOk = ($migration['check'])($db);
        if ($verifyOk) {
            echo green(" ✓ OK") . dim(" ({$result['statements']} statements)\n");
        } else {
            echo yellow(" ⚠ RAN but check still fails — manual verification needed\n");
            $failed++;
        }
    } else {
        echo red(" ✗ FAILED\n");
        foreach ($result['errors'] as $err) {
            echo red("     " . $err . "\n");
        }
        $failed++;
    }
}

echo "\n";

if ($failed > 0) {
    echo red("✗ {$failed} migration(s) failed. See errors above.\n\n");
}

// ── Phase 3: Post-migration verification ────────────────────────────────────
runVerification($db);

if ($failed > 0) {
    exit(1);
}
exit(0);

// ── Verification function ───────────────────────────────────────────────────
function runVerification(PDO $db): void
{
    echo bold("── Phase 3: Full schema verification ──────────────────────\n\n");

    $requiredTables = [
        'tenants', 'users', 'wallets', 'loyalty_tiers', 'transactions',
        'push_subscriptions', 'email_queue', 'audit_log',
        'password_resets',
        'notifications', 'email_config', 'email_templates', 'email_log',
        'platform_fees', 'platform_invoices', 'platform_fee_log',
        'platform_settings', 'verification_attempts',
        'user_credentials', 'webauthn_challenges',
    ];

    $requiredColumns = [
        'tenants' => [
            'uuid', 'name', 'slug', 'brand_color', 'secondary_color', 'secret_key',
            'mollie_status', 'whitelisted_ips', 'is_active', 'feature_push',
            'feature_marketing', 'contact_name', 'contact_email', 'phone',
            'address', 'postal_code', 'city', 'country',
            // Platform fee migration
            'platform_fee_percentage', 'platform_fee_min_cents',
            'mollie_connect_status', 'invoice_period', 'btw_number',
            'invoice_email', 'platform_fee_note',
            // Gated onboarding migration
            'verification_soft_limit', 'verification_hard_limit',
            'verification_cooldown_sec', 'verification_max_attempts',
            // Verification toggle migration
            'verification_required',
            // Points toggle migration
            'points_enabled',
        ],
        'users' => [
            'tenant_id', 'email', 'password_hash', 'role', 'first_name', 'last_name',
            'birthdate', 'photo_url', 'photo_status',
            // Gated onboarding migration
            'account_status', 'verified_at', 'verified_by',
            'verified_birthdate', 'suspended_reason', 'suspended_at', 'suspended_by',
            // FCM & profile columns migration (fcm_token replaces legacy push_token)
            'fcm_token', 'phone', 'street', 'house_number', 'postal_code', 'city',
        ],
        'loyalty_tiers' => [
            'id', 'tenant_id', 'name', 'min_deposit_cents',
            // Package tiers migration
            'topup_amount_cents', 'alcohol_discount_perc', 'food_discount_perc',
            'points_multiplier', 'is_active', 'sort_order',
            // Model type migration
            'model_type', 'bonus_percentage',
        ],
        'transactions' => [
            'id', 'tenant_id', 'user_id', 'type', 'final_total_cents',
            // created_at migration
            'created_at',
        ],
    ];

    $tablesOk = true;
    $columnsOk = true;

    // Check tables
    echo "  " . bold("Tables:\n");
    foreach ($requiredTables as $table) {
        if (tableExists($db, $table)) {
            echo "    " . green('✓') . " {$table}\n";
        } else {
            echo "    " . red('✗') . " {$table} " . red('MISSING') . "\n";
            $tablesOk = false;
        }
    }

    // Check columns
    echo "\n  " . bold("Critical Columns:\n");
    foreach ($requiredColumns as $table => $columns) {
        echo "    " . dim("── {$table} ──") . "\n";
        foreach ($columns as $column) {
            if (columnExists($db, $table, $column)) {
                echo "      " . green('✓') . " {$column}\n";
            } else {
                echo "      " . red('✗') . " {$column} " . red('MISSING') . "\n";
                $columnsOk = false;
            }
        }
    }

    // Summary
    $tableCount = count($requiredTables);
    echo "\n";
    $allOk = $tablesOk && $columnsOk;
    if ($allOk) {
        echo green("✓ All {$tableCount} tables and all critical columns verified.\n");
        echo green("✓ Database schema is complete and up to date.\n\n");
    } else {
        if (!$tablesOk) echo red("✗ Some tables are missing.\n");
        if (!$columnsOk) echo red("✗ Some columns are missing.\n");
        echo yellow("  Run this script again or apply migrations manually.\n\n");
    }
}
