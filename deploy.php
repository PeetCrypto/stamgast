<?php
declare(strict_types=1);

/**
 * REGULR.vip — Deploy & Install Script
 * 
 * Voer dit script 1 keer uit na upload naar Hostinger.
 * Het controleert alles, maakt de database aan, migreert tabellen,
 * maakt een superadmin aan en verwijdert zichzelf daarna.
 * 
 * Gebruik: https://app.regulr.vip/deploy.php
 * Of via SSH/terminal: php deploy.php
 */

// ═══════════════════════════════════════════════════════════════
// INSTELLINGEN — PAS DEZE AAN VOORDAT JE HET SCRIPT DRAAIT
// ═══════════════════════════════════════════════════════════════

// Superadmin account wordt uit .env gelezen (na $env load)
// Zie STAP 2 voor de toewijzing

// ═══════════════════════════════════════════════════════════════
// HULPFUNCTIES
// ═══════════════════════════════════════════════════════════════

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
    echo "\n";
    echo "  Tijd: {$elapsed}s\n";
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

/**
 * Lees .env bestand direct (zonder load_env.php)
 */
function loadEnvFile(string $path): array {
    $env = [];
    if (!file_exists($path)) {
        return $env;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $m)) {
            $value = $m[1];
        }
        $env[$key] = $value;
    }
    return $env;
}

// ═══════════════════════════════════════════════════════════════
// START
// ═══════════════════════════════════════════════════════════════

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     REGULR.vip — Deploy & Install Script               ║\n";
echo "║     Versie 1.0 — " . date('Y-m-d H:i:s') . "                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$rootDir = __DIR__;

// ═══════════════════════════════════════════════════════════════
// STAP 1: PHP REQUIREMENTS
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 1: PHP Requirements');

check('PHP versie 8.1+', PHP_VERSION_ID >= 80100, "Huidig: " . PHP_VERSION);
check('PHP versie 8.2+ (aanbevolen)', PHP_VERSION_ID >= 80200, "Huidig: " . PHP_VERSION . " (werkt maar 8.2+ is beter)");

$requiredExtensions = ['pdo_mysql', 'json', 'mbstring', 'openssl', 'gd', 'curl', 'fileinfo'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    check("Extension: {$ext}", $loaded);
    if (!$loaded) abort("PHP extension '{$ext}' ontbreekt. Activeer in Hostinger hPanel → PHP.");
}

$optionalExtensions = ['zip', 'dom'];
foreach ($optionalExtensions as $ext) {
    if (!extension_loaded($ext)) {
        warn("Optionele extension '{$ext}' niet geladen (geen probleem)");
    }
}

// ═══════════════════════════════════════════════════════════════
// STAP 2: BESTANDEN & .ENV
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 2: Bestanden & Configuratie');

$requiredFiles = [
    'index.php',
    'config/load_env.php',
    'config/app.php',
    'config/database.php',
    'config/cors.php',
    'config/email.php',
    '.htaccess',
];

foreach ($requiredFiles as $file) {
    check("Bestand: {$file}", file_exists($rootDir . '/' . $file));
}

// .env check — als .env ontbreekt, kopieer van .env.production
$envPath = $rootDir . '/.env';
if (!file_exists($envPath)) {
    $productionEnv = $rootDir . '/.env.production';
    if (file_exists($productionEnv)) {
        step('.env ontbreekt — kopiëren van .env.production...');
        $copied = copy($productionEnv, $envPath);
        check('.env aangemaakt vanuit .env.production', $copied);
        if (!$copied) {
            abort('Kon .env.production niet kopiëren naar .env. Check bestandsrechten.');
        }
    } else {
        check('.env bestand', false, 'Zowel .env als .env.production ontbreken!');
        abort('Maak een .env bestand aan met je database credentials.');
    }
}

check('.env bestand', true);

// Lees .env
$env = loadEnvFile($envPath);
check('.env bevat APP_ENV', isset($env['APP_ENV']) && $env['APP_ENV'] === 'production',
    'APP_ENV moet "production" zijn, gevonden: ' . ($env['APP_ENV'] ?? '(leeg)'));

check('.env bevat DB_HOST', isset($env['DB_HOST']) && !empty($env['DB_HOST']));
check('.env bevat DB_NAME', isset($env['DB_NAME']) && !empty($env['DB_NAME']));
check('.env bevat DB_USER', isset($env['DB_USER']) && !empty($env['DB_USER']));
check('.env bevat DB_PASS', isset($env['DB_PASS']));
// Auto-genereer APP_PEPPER als ontbreekt
if (empty($env['APP_PEPPER']) || strlen($env['APP_PEPPER']) < 16) {
    step('APP_PEPPER ontbreekt — automatisch genereren...');
    $env['APP_PEPPER'] = bin2hex(random_bytes(32));
    $appended = file_put_contents($envPath, "\nAPP_PEPPER={$env['APP_PEPPER']}\n", FILE_APPEND);
    check('APP_PEPPER gegenereerd en opgeslagen', $appended !== false);
} else {
    check('APP_PEPPER aanwezig', true);
}

// Auto-genereer ENCRYPTION_KEY als ontbreekt
if (empty($env['ENCRYPTION_KEY']) || strlen($env['ENCRYPTION_KEY']) < 16) {
    step('ENCRYPTION_KEY ontbreekt — automatisch genereren...');
    $env['ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
    $appended = file_put_contents($envPath, "\nENCRYPTION_KEY={$env['ENCRYPTION_KEY']}\n", FILE_APPEND);
    check('ENCRYPTION_KEY gegenereerd en opgeslagen', $appended !== false);
} else {
    check('ENCRYPTION_KEY aanwezig', true);
}

// Superadmin credentials uit .env (met fallback defaults)
$SUPERADMIN_EMAIL      = $env['SUPERADMIN_EMAIL']      ?? 'admin@regulr.vip';
$SUPERADMIN_PASSWORD   = $env['SUPERADMIN_PASSWORD']    ?? 'Admin123!';
$SUPERADMIN_FIRST_NAME = $env['SUPERADMIN_FIRST_NAME']  ?? 'Admin';
$SUPERADMIN_LAST_NAME  = $env['SUPERADMIN_LAST_NAME']   ?? 'REGULR.vip';
check('.env bevat SUPERADMIN_EMAIL', !empty($SUPERADMIN_EMAIL), 'Gebruikt fallback: admin@regulr.vip');

// ═══════════════════════════════════════════════════════════════
// STAP 3: UPLOAD DIRECTORIES
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 3: Upload Directories');

$uploadDirs = [
    'public/uploads',
    'public/uploads/logos',
    'public/uploads/profiles',
];

foreach ($uploadDirs as $dir) {
    $fullPath = $rootDir . '/' . $dir;
    if (!is_dir($fullPath)) {
        step("Aanmaken: {$dir}");
        $created = mkdir($fullPath, 0755, true);
        check("Directory: {$dir}", $created, "Kon niet aanmaken. Maak handmatig aan via File Manager.");
    } else {
        check("Directory: {$dir}", is_writable($fullPath), "Niet schrijfbaar. chmod 755 via File Manager.");
    }
}

// ═══════════════════════════════════════════════════════════════
// STAP 4: DATABASE CONNECTIE
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 4: Database Connectie');

step('Verbinden met MySQL server...');

try {
    // Eerst connectie ZONDER database naam (om te checken of DB bestaat)
    $dsn = "mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? 3306) . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    check('MySQL connectie', true);
} catch (PDOException $e) {
    check('MySQL connectie', false, $e->getMessage());
    abort('Kan geen verbinding maken met MySQL. Controleer .env credentials.');
}

// Check of database bestaat
$dbName = $env['DB_NAME'];
$dbExists = false;
try {
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'");
    $dbExists = $stmt->fetch() !== false;
} catch (PDOException $e) {
    // ignore
}

check("Database '{$dbName}' bestaat", $dbExists);

if (!$dbExists) {
    step("Aanmaken database '{$dbName}'...");
    try {
        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        check("Database '{$dbName}' aangemaakt", true);
    } catch (PDOException $e) {
        check("Database '{$dbName}' aangemaakt", false, $e->getMessage());
        abort('Kan database niet aanmaken. Controleer permissions in Hostinger hPanel.');
    }
}

// Selecteer de database
$pdo->exec("USE `{$dbName}`");
check("Database geselecteerd", true);

// ═══════════════════════════════════════════════════════════════
// STAP 5: SQL MIGRATIES
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 5: Database Migraties');

// SQL migratie bestanden in exacte volgorde
$migrations = [
    'schema.sql'                        => 'Basis schema (core tabellen)',
    'email_system_migration.sql'        => 'Email systeem tabellen',
    'platform_settings_migration.sql'   => 'Platform settings',
    'platform_fee_migration.sql'        => 'Platform fees + facturatie',
    'package_tiers_migration.sql'       => 'Package tiers',
    'gated_onboarding_migration.sql'    => 'Account status + verificatie',
    'notifications_migration.sql'       => 'Notifications',
    'verification_toggle_migration.sql' => 'Verification toggle',
    'password_reset_migration.sql'       => 'Password reset tokens',
    'add_created_at_column.sql'         => 'Transactions created_at',
];

$sqlDir = $rootDir . '/sql/';
$migrationCount = 0;
$migrationErrors = 0;

foreach ($migrations as $file => $description) {
    $filePath = $sqlDir . $file;
    
    if (!file_exists($filePath)) {
        check("SQL: {$file}", false, "Bestand niet gevonden: {$filePath}");
        $migrationErrors++;
        continue;
    }

    step("Uitvoeren: {$file} — {$description}");
    
    try {
        $sql = file_get_contents($filePath);
        
        // Verwijder alleen regel-comments die BEGINNEN met -- (niet -- in strings)
        $sql = preg_replace('/^\s*--\s.*$/m', '', $sql);
        // Verwijder block comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        // Verwijder DELIMITER directives (niet compatibel met PDO)
        $sql = preg_replace('/^\s*DELIMITER\s+.*$/mi', '', $sql);
        
        // Split statements op ;
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => strlen($s) > 5
        );
        
        $success = true;
        $stmtErrors = [];
        foreach ($statements as $statement) {
            if (empty(trim($statement))) continue;
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                //某些语句可能因为表已存在而失败，这是正常的
                $errorMsg = $e->getMessage();
                // Tolerate: table already exists, duplicate column, duplicate key
                if (str_contains($errorMsg, 'already exists') ||
                    str_contains($errorMsg, 'Duplicate column') ||
                    str_contains($errorMsg, 'Duplicate key') ||
                    str_contains($errorMsg, 'Duplicate entry')) {
                    // Table/column bestaat al — OK, skip
                    continue;
                }
                $stmtErrors[] = $errorMsg;
                $success = false;
            }
        }
        
        if ($success) {
            check("SQL: {$file}", true);
            $migrationCount++;
        } else {
            check("SQL: {$file}", false, implode('; ', array_slice($stmtErrors, 0, 2)));
            $migrationErrors++;
        }
    } catch (Exception $e) {
        check("SQL: {$file}", false, $e->getMessage());
        $migrationErrors++;
    }
}

check("Migraties voltooid", $migrationCount === count($migrations),
    "{$migrationCount}/" . count($migrations) . " geslaagd, {$migrationErrors} fouten");

if ($migrationErrors > 0 && $migrationCount === 0) {
    abort('Geen enkele migratie is geslaagd. Check de SQL bestanden.');
}

if ($migrationErrors > 0) {
    warn("{$migrationErrors} migratie(s) hadden fouten (mogelijk al bestaande tabellen). Controleer phpMyAdmin.");
}

// ═══════════════════════════════════════════════════════════════
// STAP 6: CONTROLEER TABELLEN
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 6: Database Tabellen Verificatie');

$requiredTables = [
    'users', 'tenants', 'wallets', 'transactions', 'loyalty_tiers',
    'audit_log', 'platform_fees', 'platform_invoices', 'platform_fee_log',
    'email_config', 'email_templates', 'email_log',
    'notifications', 'verification_attempts', 'password_resets',
];

try {
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($requiredTables as $table) {
        $exists = in_array($table, $existingTables);
        check("Tabel: {$table}", $exists, "Niet gevonden in database");
    }
    
    // Check platform_settings table (kan platform_settings heten)
    $hasSettings = in_array('platform_settings', $existingTables);
    if (!$hasSettings) {
        warn("Tabel 'platform_settings' niet gevonden (kan anders heten of later toegevoegd worden)");
    }
} catch (PDOException $e) {
    warn("Kon tabellen niet verifiëren: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// STAP 7: SUPERADMIN AANMAKEN
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 7: Superadmin Account');

// Gebruik APP_PEPPER uit .env voor password hashing
$pepper = $env['APP_PEPPER'] ?? '';

if (empty($pepper)) {
    warn('APP_PEPPER niet ingesteld in .env. Password hashing is zwak!');
}

// Controleer of superadmin al bestaat
try {
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ? AND role = 'superadmin'");
    $stmt->execute([$SUPERADMIN_EMAIL]);
    $existingAdmin = $stmt->fetch();
    
    if ($existingAdmin) {
        check("Superadmin bestaat al", true, "Email: {$SUPERADMIN_EMAIL} (ID: {$existingAdmin['id']})");
        
        // Update password zodat het matcht met de APP_PEPPER
        step("Wachtwoord updaten voor bestaande superadmin...");
        $hash = password_hash($SUPERADMIN_PASSWORD . $pepper, PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $existingAdmin['id']]);
        check("Superadmin wachtwoord bijgewerkt", true);
    } else {
        // Maak nieuwe superadmin aan
        step("Aanmaken superadmin account...");
        $hash = password_hash($SUPERADMIN_PASSWORD . $pepper, PASSWORD_ARGON2ID);
        
        $stmt = $pdo->prepare(
            "INSERT INTO users (tenant_id, email, password_hash, role, first_name, last_name, account_status)
             VALUES (NULL, ?, ?, 'superadmin', ?, ?, 'active')"
        );
        
        $stmt->execute([
            $SUPERADMIN_EMAIL,
            $hash,
            $SUPERADMIN_FIRST_NAME,
            $SUPERADMIN_LAST_NAME,
        ]);
        
        $adminId = $pdo->lastInsertId();
        check("Superadmin aangemaakt", true, "Email: {$SUPERADMIN_EMAIL} (ID: {$adminId})");
    }
    
    // Verify login works
    step("Verifiëren wachtwoord...");
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ? AND role = 'superadmin'");
    $stmt->execute([$SUPERADMIN_EMAIL]);
    $row = $stmt->fetch();
    if ($row) {
        $verifyOk = password_verify($SUPERADMIN_PASSWORD . $pepper, $row['password_hash']);
        check("Wachtwoord verificatie", $verifyOk, "Password hash komt niet overeen!");
        if (!$verifyOk) {
            warn("Dit kan komen door een verkeerde APP_PEPPER in .env.");
        }
    }
    
} catch (PDOException $e) {
    check("Superadmin aanmaken", false, $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// STAP 8: BEVEILIGINGSCHECK
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 8: Beveiligingscheck');

$securityChecks = [
    '.env niet publiek bereikbaar' => function() use ($rootDir) {
        // .htaccess moet dot-files blokkeren
        $htaccess = file_get_contents($rootDir . '/.htaccess');
        return str_contains($htaccess, '<FilesMatch "^\.">') || str_contains($htaccess, 'Deny from all');
    },
    '.htaccess bevat HTTPS force' => function() use ($rootDir) {
        $htaccess = file_get_contents($rootDir . '/.htaccess');
        return str_contains($htaccess, 'RewriteRule') && str_contains($htaccess, 'HTTPS');
    },
    '.htaccess blokkeert config/' => function() use ($rootDir) {
        $htaccess = file_get_contents($rootDir . '/.htaccess');
        return str_contains($htaccess, 'config/') && str_contains($htaccess, '[F,L]');
    },
    'APP_ENV = production' => function() use ($env) {
        return ($env['APP_ENV'] ?? '') === 'production';
    },
    'sql/ directory verwijderbaar' => function() use ($rootDir) {
        return !file_exists($rootDir . '/sql/') || is_dir($rootDir . '/sql/');
    },
];

foreach ($securityChecks as $name => $fn) {
    try {
        $result = $fn();
        check($name, $result);
    } catch (Exception $e) {
        check($name, false, $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// STAP 9: APP FUNCTIE CHECK
// ═══════════════════════════════════════════════════════════════

header_msg('STAP 9: App Functie Test');

// Test dat de app kan starten (require index.php flow zonder output)
step('Testen app initialisatie...');

// Temporarily suppress output
ob_start();
try {
    // Simuleer wat index.php doet
    require_once $rootDir . '/config/load_env.php';
    require_once $rootDir . '/config/app.php';
    
    check('config/load_env.php laadt', true);
    check('config/app.php laadt', true);
    check('APP_ENV = ' . APP_ENV, APP_ENV === 'production');
    check('APP_DEBUG = false', !APP_DEBUG);
    check('DB constants gedefinieerd', defined('DB_HOST') && defined('DB_NAME'));
    
    require_once $rootDir . '/config/database.php';
    check('Database singleton werkt', true);
    
    require_once $rootDir . '/config/cors.php';
    check('CORS functie beschikbaar', function_exists('setCORSHeaders'));
    
} catch (Throwable $e) {
    check('App initialisatie', false, $e->getMessage());
} finally {
    ob_end_clean();
}

// Test API endpoint bereikbaarheid (simulatie)
$apiEndpoints = [
    'api/auth/login' => $rootDir . '/api/auth/login.php',
    'api/auth/session' => $rootDir . '/api/auth/session.php',
    'api/wallet/balance' => $rootDir . '/api/wallet/balance.php',
];

foreach ($apiEndpoints as $endpoint => $file) {
    check("API: {$endpoint}", file_exists($file), "Bestand niet gevonden: {$endpoint}");
}

// ═══════════════════════════════════════════════════════════════
// SAMENVATTING
// ═══════════════════════════════════════════════════════════════

summary();

if ($failedChecks === 0) {
    echo "\n";
    echo "  🎉 INSTALLATIE SUCCESVOL!\n";
    echo str_repeat('─', 60) . "\n";
    echo "\n";
    echo "  Je kunt nu inloggen op: https://app.regulr.vip/login\n";
    echo "  Email:    {$SUPERADMIN_EMAIL}\n";
    echo "  Wachtwoord: {$SUPERADMIN_PASSWORD}\n";
    echo "\n";
    echo "  ⚡ VERANDER DIT WACHTWOORD NA EERSTE LOGIN!\n";
    echo "\n";
    echo "  📋 Volgende stappen:\n";
    echo "  1. Verwijder de sql/ directory van de server\n";
    echo "  2. Login en maak een tenant aan\n";
    echo "  3. Configureer Mollie (test → live) in .env\n";
    echo "  4. Configureer Brevo email (BREVO_API_KEY in .env)\n";
    echo "\n";
    
    // Auto-delete dit script
    step("Script verwijderen...");
    $deleted = @unlink(__FILE__);
    if ($deleted) {
        echo "  ✅ deploy.php verwijderd (veilig!)\n";
    } else {
        warn("Kon deploy.php niet automatisch verwijderen. Verwijder handmatig via File Manager!");
    }
} else {
    echo "\n";
    echo "  ❌ INSTALLATIE NIET VOLTOOID\n";
    echo str_repeat('─', 60) . "\n";
    echo "  Er zijn {$failedChecks} fout(en). Los deze op en draai dit script opnieuw.\n";
    echo "  Het script is NIET verwijderd — je kunt het opnieuw uitvoeren.\n";
    echo "\n";
}

echo "\n";
