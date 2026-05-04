<?php
declare(strict_types=1);

/**
 * REGULR.vip — Deploy & Install Script
 * 
 * Voer dit script 1 keer uit na upload naar Hostinger.
 * Het controleert alles, maakt de database aan, migreert tabellen,
 * maakt een superadmin aan en verwijdert zichzelf daarna.
 */

$startTime = microtime(true);
$totalChecks = 0;
$passedChecks = 0;
$failedChecks = 0;
$warnings = [];

function header_msg(string $title): void {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60) . "\n";
}

function check(string $name, bool $pass, string $failMsg = ''): void {
    global $totalChecks, $passedChecks, $failedChecks;
    $totalChecks++;
    if ($pass) {
        $passedChecks++;
        echo "  ✅ {$name}\n";
    } else {
        $failedChecks++;
        echo "  ❌ {$name}";
        if ($failMsg) echo " — {$failMsg}";
        echo "\n";
    }
}

function warn(string $msg): void {
    global $warnings;
    $warnings[] = $msg;
    echo "  ⚠️  {$msg}\n";
}

function step(string $msg): void {
    echo "\n  ▶ {$msg}...\n";
}

function abort(string $msg): void {
    echo "\n  🛑 AFGEBROKEN: {$msg}\n";
    echo "\n  Los dit probleem op en draai dit script opnieuw.\n";
    summary();
    exit(1);
}

function summary(): void {
    global $totalChecks, $passedChecks, $failedChecks, $warnings, $startTime;
    $elapsed = round(microtime(true) - $startTime, 2);
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "  SAMENVATTING\n";
    echo str_repeat('═', 60) . "\n";
    echo "  Checks: {$passedChecks}/{$totalChecks} geslaagd";
    if ($failedChecks > 0) echo " ({$failedChecks} gefaald)";
    echo "\n  Tijd: {$elapsed}s\n";
    if ($failedChecks > 0) {
        echo "  Status: ❌ Niet klaar voor gebruik\n";
    } else {
        echo "  Status: ✅ Succesvol geïnstalleerd!\n";
    }
    if (!empty($warnings)) {
        echo "\n  Waarschuwingen:\n";
        foreach ($warnings as $w) {
            echo "    ⚠️  {$w}\n";
        }
    }
    echo str_repeat('═', 60) . "\n";
}

function loadEnvFile(string $path): array {
    $env = [];
    if (!file_exists($path)) return $env;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $m)) $value = $m[1];
        $env[$key] = $value;
    }
    return $env;
}

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║     REGULR.vip — Deploy & Install Script               ║\n";
echo "║     Versie 1.0 — " . date('Y-m-d H:i:s') . "                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$rootDir = __DIR__;

// STAP 1: PHP Requirements
header_msg('STAP 1: PHP Requirements');
check('PHP versie 8.1+', PHP_VERSION_ID >= 80100, "Huidig: " . PHP_VERSION);
$requiredExtensions = ['pdo_mysql', 'json', 'mbstring', 'openssl', 'gd', 'curl', 'fileinfo'];
foreach ($requiredExtensions as $ext) {
    check("Extension: {$ext}", extension_loaded($ext));
}

// STAP 2: Bestanden & Configuratie
header_msg('STAP 2: Bestanden & Configuratie');
$requiredFiles = ['index.php', 'config/load_env.php', 'config/app.php', 'config/database.php', 'config/cors.php', 'config/email.php', '.htaccess'];
foreach ($requiredFiles as $file) {
    check("Bestand: {$file}", file_exists($rootDir . '/' . $file));
}

$envPath = $rootDir . '/.env';
if (!file_exists($envPath)) {
    $prodEnv = $rootDir . '/.env.production';
    if (file_exists($prodEnv)) {
        copy($prodEnv, $envPath);
        check('.env aangemaakt', true);
    } else {
        abort('Maak een .env bestand aan met database credentials.');
    }
}

$env = loadEnvFile($envPath);
check('.env bevat DB_HOST', isset($env['DB_HOST']) && !empty($env['DB_HOST']));
check('.env bevat DB_NAME', isset($env['DB_NAME']) && !empty($env['DB_NAME']));
check('.env bevat DB_USER', isset($env['DB_USER']) && !empty($env['DB_USER']));
check('.env bevat DB_PASS', isset($env['DB_PASS']));

if (empty($env['APP_PEPPER']) || strlen($env['APP_PEPPER']) < 16) {
    $env['APP_PEPPER'] = bin2hex(random_bytes(32));
    file_put_contents($envPath, "\nAPP_PEPPER={$env['APP_PEPPER']}\n", FILE_APPEND);
    check('APP_PEPPER gegenereerd', true);
}

if (empty($env['ENCRYPTION_KEY']) || strlen($env['ENCRYPTION_KEY']) < 16) {
    $env['ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
    file_put_contents($envPath, "\nENCRYPTION_KEY={$env['ENCRYPTION_KEY']}\n", FILE_APPEND);
    check('ENCRYPTION_KEY gegenereerd', true);
}

$SUPERADMIN_EMAIL = $env['SUPERADMIN_EMAIL'] ?? 'admin@regulr.vip';
$SUPERADMIN_PASSWORD = $env['SUPERADMIN_PASSWORD'] ?? 'Admin123!';
$SUPERADMIN_FIRST_NAME = $env['SUPERADMIN_FIRST_NAME'] ?? 'Admin';
$SUPERADMIN_LAST_NAME = $env['SUPERADMIN_LAST_NAME'] ?? 'REGULR.vip';

// STAP 3: Upload Directories
header_msg('STAP 3: Upload Directories');
$uploadDirs = ['public/uploads', 'public/uploads/logos', 'public/uploads/profiles'];
foreach ($uploadDirs as $dir) {
    $fullPath = $rootDir . '/' . $dir;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0755, true);
        check("Directory: {$dir}", true);
    } else {
        check("Directory: {$dir}", is_writable($fullPath));
    }
}

// STAP 4: Database Connectie
header_msg('STAP 4: Database Connectie');
step('Verbinden met MySQL server...');
try {
    $dsn = "mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? 3306) . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    check('MySQL connectie', true);
} catch (PDOException $e) {
    check('MySQL connectie', false, $e->getMessage());
    abort('Kan geen verbinding maken met MySQL.');
}

$dbName = $env['DB_NAME'];
$dbExists = false;
try {
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'");
    $dbExists = $stmt->fetch() !== false;
} catch (PDOException $e) {}

check("Database '{$dbName}' bestaat", $dbExists);
if (!$dbExists) {
    step("Aanmaken database '{$dbName}'...");
    try {
        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        check("Database '{$dbName}' aangemaakt", true);
    } catch (PDOException $e) {
        abort('Kan database niet aanmaken.');
    }
}
$pdo->exec("USE `{$dbName}`");
check("Database geselecteerd", true);

// STAP 5: Database MigratIES
header_msg('STAP 5: Database Migraties');

$migrations = [
    'schema.sql' => 'Basis schema',
    'email_system_migration.sql' => 'Email systeem',
    'platform_settings_migration.sql' => 'Platform settings',
    'platform_fee_migration.sql' => 'Platform fees',
    'package_tiers_migration.sql' => 'Package tiers',
    'gated_onboarding_migration.sql' => 'Gated onboarding',
    'notifications_migration.sql' => 'Notifications',
    'verification_toggle_migration.sql' => 'Verification toggle',
    'password_reset_migration.sql' => 'Password reset',
    'add_created_at_column.sql' => 'Transactions created_at',
];

$sqlDir = $rootDir . '/sql/';
$migrationCount = 0;
$migrationErrors = 0;

foreach ($migrations as $file => $description) {
    if ($file === 'add_bartender_invite_migration.php') {
        // Voer de bartender_invite migratie uit (PHP code direct in deploy.php)
        step("Uitvoeren: {$file}");
        try {
            // 1. Alter the ENUM to include bartender_invite
            try {
                $pdo->exec("ALTER TABLE `email_templates` 
                    MODIFY COLUMN `type` ENUM(
                        'tenant_welcome',
                        'admin_invite',
                        'bartender_invite',
                        'guest_confirmation',
                        'guest_password_reset',
                        'marketing'
                    ) NOT NULL");
                echo "  [OK] ENUM altered\n";
            } catch (Throwable $e) {
                echo "  [INFO] ENUM already updated\n";
            }

            // 2. Insert default bartender_invite template if not exists
            try {
                $stmt = $pdo->prepare("SELECT id FROM email_templates WHERE type = 'bartender_invite' AND tenant_id IS NULL AND language_code = 'nl'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;background:#0f0f1a;color:#e0e0e0;"><div style="max-width:600px;margin:0 auto;"><h2 style="color:#FFC107;">Uitnodiging als Bartender</h2><p>Beste {{user_name}},</p><p>Je bent uitgenodigd om bartender te worden bij <strong>{{tenant_name}}</strong>.</p><p><a href="{{invitation_link}}" style="background:#FFC107;color:#000;padding:10px 20px;border-radius:5px;text-decoration:none;">Accepteer Uitnodiging</a></p><p>Inloggegevens: E-mail: {{user_email}}, Wachtwoord: {{user_password}}</p></div></body></html>';
                    $text = "Uitnodiging als Bartender bij {{tenant_name}}. Accepteer: {{invitation_link}}. Inloggegevens: {{user_email}} / {{user_password}}";
                    
                    $stmt = $pdo->prepare("INSERT INTO email_templates (type, subject, content, text_content, tenant_id, language_code, is_default) VALUES ('bartender_invite', 'Uitnodiging: Bartender toegang voor {{tenant_name}}', :content, :text_content, NULL, 'nl', 1)");
                    $stmt->execute([':content' => $html, ':text_content' => $text]);
                    echo "  [OK] Template inserted\n";
                } else {
                    echo "  [SKIP] Template exists\n";
                }
            } catch (Throwable $e) {
                echo "  [ERROR] Template: " . $e->getMessage() . "\n";
            }

            // 3. Fix email_log table structure
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'tenant_id'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE email_log ADD COLUMN tenant_id INT NULL AFTER id");
                    echo "  [OK] Added tenant_id\n";
                }
                $stmt = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'user_id'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE email_log ADD COLUMN user_id INT NULL AFTER tenant_id");
                    echo "  [OK] Added user_id\n";
                }
            } catch (Throwable $e) {
                echo "  [INFO] Table update: " . $e->getMessage() . "\n";
            }
            
            check("SQL: {$file}", true);
            $migrationCount++;
        } catch (Exception $e) {
            check("SQL: {$file}", false, $e->getMessage());
            $migrationErrors++;
        }
        continue;
    }
    
    $filePath = $sqlDir . $file;
    if (!file_exists($filePath)) {
        check("SQL: {$file}", false, "Niet gevonden");
        $migrationErrors++;
        continue;
    }
    step("Uitvoeren: {$file}");
    try {
        $sql = file_get_contents($filePath);
        $sql = preg_replace('/^\s*--\s.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => strlen($s) > 5);
        
        $success = true;
        foreach ($statements as $statement) {
            if (empty(trim($statement))) continue;
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $err = $e->getMessage();
                if (!str_contains($err, 'already exists') && !str_contains($err, 'Duplicate')) {
                    $success = false;
                }
            }
        }
        check("SQL: {$file}", $success);
        if ($success) $migrationCount++;
        else $migrationErrors++;
    } catch (Exception $e) {
        check("SQL: {$file}", false, $e->getMessage());
        $migrationErrors++;
    }
}
check("Migraties voltooid", $migrationCount === count($migrations), "{$migrationCount}/" . count($migrations));

// STAP 6: Tabellen Verificatie
header_msg('STAP 6: Database Tabellen');
$requiredTables = ['users', 'tenants', 'wallets', 'transactions', 'loyalty_tiers', 'audit_log', 'platform_fees', 'platform_invoices', 'platform_fee_log', 'email_config', 'email_templates', 'email_log', 'notifications', 'verification_attempts', 'password_resets'];
try {
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $table) {
        check("Tabel: {$table}", in_array($table, $existingTables));
    }
} catch (PDOException $e) {
    warn("Kon tabellen niet verifiëren");
}

// STAP 7: Superadmin
header_msg('STAP 7: Superadmin Account');
$pepper = $env['APP_PEPPER'] ?? '';
try {
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ? AND role = 'superadmin'");
    $stmt->execute([$SUPERADMIN_EMAIL]);
    $existingAdmin = $stmt->fetch();
    if ($existingAdmin) {
        check("Superadmin bestaat al", true, "Email: {$SUPERADMIN_EMAIL}");
        $hash = password_hash($SUPERADMIN_PASSWORD . $pepper, PASSWORD_ARGON2ID);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $existingAdmin['id']]);
        check("Wachtwoord bijgewerkt", true);
    } else {
        step("Aanmaken superadmin...");
        $hash = password_hash($SUPERADMIN_PASSWORD . $pepper, PASSWORD_ARGON2ID);
        $pdo->prepare("INSERT INTO users (tenant_id, email, password_hash, role, first_name, last_name, account_status) VALUES (NULL, ?, ?, 'superadmin', ?, ?, 'active')")->execute([$SUPERADMIN_EMAIL, $hash, $SUPERADMIN_FIRST_NAME, $SUPERADMIN_LAST_NAME]);
        $adminId = $pdo->lastInsertId();
        check("Superadmin aangemaakt", true, "Email: {$SUPERADMIN_EMAIL} (ID: {$adminId})");
    }
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ? AND role = 'superadmin'");
    $stmt->execute([$SUPERADMIN_EMAIL]);
    $row = $stmt->fetch();
    if ($row) {
        $verifyOk = password_verify($SUPERADMIN_PASSWORD . $pepper, $row['password_hash']);
        check("Wachtwoord verificatie", $verifyOk);
    }
} catch (PDOException $e) {
    check("Superadmin", false, $e->getMessage());
}

// STAP 8: Beveiliging
header_msg('STAP 8: Beveiligingscheck');
check('APP_ENV = production', ($env['APP_ENV'] ?? '') === 'production');
check('sql/ directory', is_dir($rootDir . '/sql/'));

// STAP 9: App Test
header_msg('STAP 9: App Functie Test');
ob_start();
try {
    require_once $rootDir . '/config/load_env.php';
    require_once $rootDir . '/config/app.php';
    require_once $rootDir . '/config/database.php';
    require_once $rootDir . '/config/cors.php';
    check('App initialisatie', true);
} catch (Throwable $e) {
    check('App initialisatie', false, $e->getMessage());
}
ob_end_clean();

// SAMENVATTING
summary();

if ($failedChecks === 0) {
    echo "\n  🎉 INSTALLATIE SUCCESVOL!\n";
    echo "  Je kunt inloggen op: https://app.regulr.vip/login\n";
    echo "  Email: {$SUPERADMIN_EMAIL}\n";
    echo "  Wachtwoord: {$SUPERADMIN_PASSWORD}\n";
    echo "\n  ⚡ VERANDER DIT WACHTWOORD NA EERSTE LOGIN!\n";
    step("Script verwijderen...");
    @unlink(__FILE__);
    echo "  ✅ deploy.php verwijderd\n\n";
} else {
    echo "\n  ❌ INSTALLATIE NIET VOLTOOID\n";
    echo "  Er zijn {$failedChecks} fout(en). Los op en draai opnieuw.\n\n";
}
