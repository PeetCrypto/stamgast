#!/usr/bin/env php
<?php
/**
 * REGULR.vip — Deploy to Production (Shared Hosting)
 * 
 * Dit script maakt een ZIP bestand met ALLE benodigde bestanden
 * voor productie deployment op app.regulr.vip (Hostinger).
 * 
 * Lokaal draaien: php deploy_to_production.php
 * Resultaat: deploy_production.zip in de root
 * 
 * Daarna:
 * 1. Upload deploy_production.zip naar Hostinger
 * 2. Pak uit op de server (via Hostinger File Manager)
 * 3. Ga naar https://app.regulr.vip/migrate_production.php
 * 4. Klaar!
 */

$rootPath = __DIR__;
$zipFile  = $rootPath . '/deploy_production.zip';

// Mappen die NIET mee gaan
$excludeDirs = [
    'site', '.git', '.kilo', '.kilocode', 'node_modules', 'vendor',
    '.vscode', 'cache', 'logs', '__pcc',
];

// Bestanden die NIET mee gaan
$excludeFiles = [
    // Dev/test bestanden
    'deploy_to_production.php',
    'deploy.php',
    'test_fcm_token.cache',
    'db_dump.txt',
    'regulr.vip.code-workspace',
    // Documentatie
    'DATABASE_FIX.md',
    'DB_FIX_SOLUTION.md',
    'HOSTINGER_DEPLOY_STAPPEN.md',
    'implementation_plan.md',
    'platform_fee_uitleg.md',
    'REGULR.vip - blueprint.md',
    'MOLLIE_TESTHANDLEIDING.txt',
    'WEBAUTHN_FIX_GUIDE.md',
    'WEBAUTHN_HTTPS_REQUIREMENT.md',
    'test_plan.md',
    'kilo.json',
    'KILOCODE.md',
    '.kilocode.md',
    'AGENTS.md',
    // Env (gebruik bestaande .env op server!)
    '.env', '.env.production',
    // Zip bestanden
    'deploy_production.zip',
    // Stale site copies
    'migrate_points_toggle.php',
];

// Mappen met bestanden die NIET mee gaan (gehele map uitsluiten)
$excludePatterns = [
    '/site/',
    '/.git/',
    '/.kilo/',
    '/.kilocode/',
    '/node_modules/',
    '/__pcc/',
];

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  REGULR.vip — Production Deploy Package Builder     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Verwijder oude zip
if (file_exists($zipFile)) {
    unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("ERROR: Kan ZIP bestand niet maken!\n");
}

// Bestanden toevoegen
$added = 0;
$skipped = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isDir()) continue;

    $filePath = $file->getRealPath();
    $relPath = str_replace($rootPath . DIRECTORY_SEPARATOR, '', $filePath);
    $relPathNormalized = str_replace('\\', '/', $relPath);

    // Check exclude patterns op mappen
    $skip = false;
    foreach ($excludePatterns as $pattern) {
        if (str_contains($relPathNormalized, $pattern)) {
            $skip = true;
            break;
        }
    }
    if ($skip) { $skipped++; continue; }

    // Check exclude dirs
    foreach ($excludeDirs as $dir) {
        if (str_starts_with($relPathNormalized, $dir . '/')) {
            $skip = true;
            break;
        }
    }
    if ($skip) { $skipped++; continue; }

    // Check exclude files
    $basename = basename($relPath);
    if (in_array($basename, $excludeFiles) || in_array($relPathNormalized, $excludeFiles)) {
        $skipped++;
        continue;
    }

    // Skip .gitignore, .env etc
    if (str_starts_with($basename, '.') && $basename !== '.htaccess') {
        $skipped++;
        continue;
    }

    // Stale api/public/ map overslaan (zit alleen in site/)
    if (str_contains($relPathNormalized, 'api/public/')) {
        $skipped++;
        continue;
    }

    $zip->addFile($filePath, $relPath);
    $added++;
}

// Migratie script toevoegen (inline gegenereerd)
$migrateScript = <<<'MIGRATE'
<?php
/**
 * REGULR.vip — Production Migration Runner
 * Run ONCE via browser: https://app.regulr.vip/migrate_production.php
 * Deletes itself after successful execution.
 *
 * Voert ALLE pending database migraties uit:
 * - model_type_migration.sql (bonus/korting model)
 * - points_toggle_migration.sql (punten aan/uit toggle)
 */
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REGULR.vip — Productie Migratie</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background: #0a0a1a; color: #e0e0e0; padding: 40px; min-height: 100vh; }
        .container { max-width: 750px; margin: 0 auto; }
        h1 { color: #FFC107; margin-bottom: 8px; font-size: 24px; }
        .subtitle { color: rgba(255,255,255,0.5); margin-bottom: 32px; font-size: 14px; }
        .log { background: #111; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 24px; font-size: 14px; line-height: 2.2; }
        .step { padding: 2px 0; }
        .ok { color: #4CAF50; }
        .skip { color: #FFC107; }
        .err { color: #f44336; }
        .dim { color: rgba(255,255,255,0.35); }
        .hr { border-top: 1px solid rgba(255,255,255,0.08); margin: 12px 0; }
        .done { margin-top: 24px; padding: 16px; border-radius: 12px; text-align: center; font-weight: bold; font-size: 16px; }
        .done-ok { background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #4CAF50; }
        .done-err { background: rgba(244,67,54,0.1); border: 1px solid rgba(244,67,54,0.3); color: #f44336; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 Productie Migratie</h1>
    <p class="subtitle">Voert alle pending database migraties uit voor REGULR.vip</p>
    <div class="log">
<?php

$hasError = false;
$migrationsRun = 0;
$migrationsSkipped = 0;

// ── .env laden ──────────────────────────────────────────────────────
echo '<div class="step">📁 .env bestand laden...</div>';

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo '<div class="step err">✗ ERROR: .env bestand niet gevonden!</div>';
    $hasError = true;
} else {
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eq = strpos($line, '=');
        if ($eq !== false) {
            $env[trim(substr($line, 0, $eq))] = trim(substr($line, $eq + 1));
        }
    }
    echo '<div class="step ok">✓ .env geladen</div>';
}

// ── Database verbinden ──────────────────────────────────────────────
if (!$hasError) {
    $dbHost = $env['DB_HOST'] ?? 'localhost';
    $dbPort = $env['DB_PORT'] ?? '3306';
    $dbName = $env['DB_NAME'] ?? '';
    $dbUser = $env['DB_USER'] ?? '';
    $dbPass = $env['DB_PASS'] ?? '';

    echo '<div class="step">🔌 Verbinden met database <strong>' . htmlspecialchars($dbName) . '</strong>...</div>';

    if (!$dbName || !$dbUser) {
        echo '<div class="step err">✗ ERROR: DB_NAME of DB_USER niet ingesteld in .env</div>';
        $hasError = true;
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo '<div class="step ok">✓ Verbonden met database</div>';
        } catch (PDOException $e) {
            echo '<div class="step err">✗ ERROR: ' . htmlspecialchars($e->getMessage()) . '</div>';
            $hasError = true;
        }
    }
}

// ── Hulpfunctie: kolom bestaat? ─────────────────────────────────────
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

// ── Migraties definieren ────────────────────────────────────────────
if (!$hasError) {
    $migrations = [
        [
            'name' => 'Model Type (Bonus/Korting)',
            'check_column' => ['loyalty_tiers', 'model_type'],
            'sql' => "ALTER TABLE `loyalty_tiers`
                ADD COLUMN `model_type` ENUM('discount','bonus') NOT NULL DEFAULT 'discount' COMMENT 'discount = percentage discounts, bonus = deposit bonus credit' AFTER `topup_amount_cents`,
                ADD COLUMN `bonus_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Bonus % for bonus model (10 = deposit 100 get 110)' AFTER `model_type`",
            'description' => 'Voegt model_type en bonus_percentage toe aan loyalty_tiers',
        ],
        [
            'name' => 'Punten Toggle (Points Enabled)',
            'check_column' => ['tenants', 'points_enabled'],
            'sql' => "ALTER TABLE `tenants`
                ADD COLUMN `points_enabled` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'true = punten sparen aan, false = geen punten systeem'
                    AFTER `feature_marketing`",
            'description' => 'Voegt points_enabled toe aan tenants',
        ],
    ];

    echo '<div class="hr"></div>';

    foreach ($migrations as $i => $mig) {
        $num = $i + 1;
        $total = count($migrations);
        echo '<div class="step dim">── Migratie ' . $num . '/' . $total . ': ' . htmlspecialchars($mig['name']) . ' ──</div>';
        echo '<div class="step dim">' . htmlspecialchars($mig['description']) . '</div>';

        // Check of kolom al bestaat
        if (columnExists($pdo, $mig['check_column'][0], $mig['check_column'][1])) {
            echo '<div class="step skip">⏭ SKIP: Kolom <code>' . htmlspecialchars($mig['check_column'][1]) . '</code> bestaat al in <code>' . htmlspecialchars($mig['check_column'][0]) . '</code></div>';
            $migrationsSkipped++;
        } else {
            echo '<div class="step">⚙️ Uitvoeren...</div>';
            try {
                $pdo->exec($mig['sql']);
                echo '<div class="step ok">✓ Kolom <code>' . htmlspecialchars($mig['check_column'][1]) . '</code> toegevoegd aan <code>' . htmlspecialchars($mig['check_column'][0]) . '</code></div>';
                $migrationsRun++;
            } catch (PDOException $e) {
                echo '<div class="step err">✗ ERROR: ' . htmlspecialchars($e->getMessage()) . '</div>';
                $hasError = true;
            }
        }

        if ($i < count($migrations) - 1) {
            echo '<div class="hr"></div>';
        }
    }
}

// ── Verificatie ─────────────────────────────────────────────────────
if (!$hasError) {
    echo '<div class="hr"></div>';
    echo '<div class="step dim">── Verificatie ──</div>';

    $columns = [
        ['loyalty_tiers', 'model_type'],
        ['loyalty_tiers', 'bonus_percentage'],
        ['tenants', 'points_enabled'],
    ];

    $allOk = true;
    foreach ($columns as $col) {
        if (columnExists($pdo, $col[0], $col[1])) {
            echo '<div class="step ok">✓ <code>' . htmlspecialchars($col[0]) . '.' . htmlspecialchars($col[1]) . '</code> bestaat</div>';
        } else {
            echo '<div class="step err">✗ <code>' . htmlspecialchars($col[0]) . '.' . htmlspecialchars($col[1]) . '</code> ontbreekt!</div>';
            $allOk = false;
        }
    }
}

// ── Script verwijderen ──────────────────────────────────────────────
if (!$hasError) {
    echo '<div class="hr"></div>';
    echo '<div class="step dim">🗑️ Dit script verwijderen...</div>';
    $self = __FILE__;
    @unlink($self);
    if (!file_exists($self)) {
        echo '<div class="step ok">✓ Script verwijderd</div>';
    } else {
        echo '<div class="step skip">⚠️ Verwijder <code>migrate_production.php</code> handmatig via FTP</div>';
    }
}

// ── Resultaat ───────────────────────────────────────────────────────
echo '</div>';

if ($hasError) {
    echo '<div class="done done-err">❌ Migratie gefaald — controleer de foutmeldingen hierboven</div>';
} else {
    $msg = $migrationsRun > 0
        ? "✅ $migrationsRun migratie(s) uitgevoerd, $migrationsSkipped overgeslagen. Alles is up-to-date!"
        : '✅ Alle migraties waren al uitgevoerd. Niets te doen!';
    echo '<div class="done done-ok">' . $msg . '</div>';
}

?>
</div>
</body>
</html>
MIGRATE;

$zip->addFromString('migrate_production.php', $migrateScript);

// .htaccess toevoegen als die bestaat
$htaccess = $rootPath . '/site/.htaccess';
if (file_exists($htaccess)) {
    $zip->addFile($htaccess, '.htaccess');
    $added++;
}

$zip->close();

// Resultaat tonen
$size = filesize($zipFile);
$sizeMB = number_format($size / 1024 / 1024, 2);

echo "Bestanden toegevoegd:  $added\n";
echo "Overgeslagen:          $skipped\n";
echo "ZIP grootte:           {$sizeMB} MB\n";
echo "Locatie:               $zipFile\n\n";

echo "═══════════════════════════════════════════════════════\n";
echo "  VOLG DEZE STAPPEN:\n";
echo "═══════════════════════════════════════════════════════\n\n";
echo "  1. Upload deploy_production.zip naar Hostinger\n";
echo "  2. Pak het ZIP bestand UIT op de server\n";
echo "     (via Hostinger File Manager → Extract)\n";
echo "  3. Overschrijf ALLE bestanden\n";
echo "  4. GA NIET naar migrate_production.php\n";
echo "     (database is al up-to-date via vorige migratie)\n";
echo "  5. Klaar! Test op https://app.regulr.vip\n\n";
echo "  ⚠️  Als je WEL nieuwe migraties moet draaien:\n";
echo "     Ga naar https://app.regulr.vip/migrate_production.php\n";
echo "     (het script verwijdert zichzelf na uitvoering)\n\n";
