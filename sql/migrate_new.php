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
        'name'   => 'Tenant Tier Model Lock (tier_model_type)',
        'file'   => 'tier_model_lock_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'tier_model_type');
        },
    ],
    [
        'name'   => 'Bonus Tiers Configuration (model_type + bonus_cents)',
        'file'   => 'bonus_tiers_config_migration.sql',
        'type'   => 'inline',
        'check'  => function (PDO $db): bool {
            // Check that at least one tier has model_type='bonus' with bonus_cents > 0
            if (!columnExists($db, 'loyalty_tiers', 'bonus_cents')) {
                return false;
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM `loyalty_tiers` WHERE `model_type` = 'bonus' AND `bonus_cents` > 0");
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        },
        'run'    => function (PDO $db): bool {
            $sql = file_get_contents(__DIR__ . '/bonus_tiers_config_migration.sql');
            if ($sql === false) return false;
            // Strip comments and execute
            $lines = explode("\n", $sql);
            $cleanLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
                $cleanLines[] = $line;
            }
            $cleanSql = implode("\n", $cleanLines);
            $statements = explode(";\n", $cleanSql);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement)) continue;
                try { $db->exec($statement); } catch (\PDOException $e) { /* idempotent */ }
            }
            return true;
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
        'name'   => 'Test Tenant Toggle',
        'file'   => 'test_tenant_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'is_test');
        },
    ],
    [
        'name'   => 'Timezone per Tenant',
        'file'   => 'timezone_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'timezone');
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
     [
         'name'   => 'Default Email Templates (guest_confirmation, guest_password_reset)',
         'type'   => 'inline',
         'check'  => function (PDO $db): bool {
             $stmt = $db->prepare("SELECT COUNT(*) FROM email_templates WHERE type IN ('guest_confirmation', 'guest_password_reset') AND tenant_id IS NULL AND language_code = 'nl'");
             $stmt->execute();
             $hasGlobals = (int) $stmt->fetchColumn() >= 2;

             $stmt2 = $db->prepare("SELECT COUNT(*) FROM email_templates WHERE type IN ('guest_confirmation', 'guest_password_reset') AND (content LIKE '%REGULR.vip Team%' OR content LIKE '%©%REGULR.vip%')");
             $stmt2->execute();
             $hasHardcoded = (int) $stmt2->fetchColumn() > 0;

             return $hasGlobals && !$hasHardcoded;
         },
         'run'    => function (PDO $db): bool {
             $templates = [
                 [
                     'type'        => 'guest_confirmation',
                     'subject'     => 'Welkom bij {{tenant_name}} — Je verificatiecode',
                     'content'     => '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:20px;background:#0f0f1a;color:#e0e0e0;font-family:Arial,Helvetica,sans-serif;"><div style="max-width:600px;margin:0 auto;background:#1a1a2e;border-radius:12px;overflow:hidden;"><div style="background:#FFC107;padding:20px;text-align:center;"><h1 style="margin:0;color:#000;font-size:24px;">{{tenant_name}}</h1></div><div style="padding:30px;"><h2 style="color:#FFC107;margin-top:0;">Welkom, {{guest_name}}!</h2><p>Bedankt voor je registratie bij <strong>{{tenant_name}}</strong>.</p><p>Jouw verificatiecode is:</p><div style="background:#16213e;border:2px dashed #FFC107;border-radius:8px;padding:20px;text-align:center;margin:20px 0;"><span style="font-size:32px;font-weight:bold;color:#FFC107;letter-spacing:4px;">{{verification_code}}</span></div><p>Geef deze code aan de barman bij je eerste bezoek om je account te verifiëren.</p><p style="color:#888;font-size:12px;margin-top:30px;">Deze code is persoonlijk. Deel hem niet met anderen.</p><hr style="border:none;border-top:1px solid #2a2a4a;margin:25px 0;"><p style="color:#888;font-size:13px;margin:0;">Met vriendelijke groet,<br><strong style="color:#e0e0e0;">{{tenant_name}}</strong></p></div></div></body></html>',
                     'text_content' => "Welkom bij {{tenant_name}}!\n\nBeste {{guest_name}},\n\nBedankt voor je registratie.\n\nJe verificatiecode is: {{verification_code}}\n\nGeef deze code aan de barman bij je eerste bezoek om je account te verifiëren.\n\nDeze code is persoonlijk. Deel hem niet met anderen.\n\nMet vriendelijke groet,\n{{tenant_name}}",
                 ],
                 [
                     'type'        => 'guest_password_reset',
                     'subject'     => '{{tenant_name}} — Wachtwoord resetten',
                     'content'     => '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:20px;background:#0f0f1a;color:#e0e0e0;font-family:Arial,Helvetica,sans-serif;"><div style="max-width:600px;margin:0 auto;background:#1a1a2e;border-radius:12px;overflow:hidden;"><div style="background:#FFC107;padding:20px;text-align:center;"><h1 style="margin:0;color:#000;font-size:24px;">{{tenant_name}}</h1></div><div style="padding:30px;"><h2 style="color:#FFC107;margin-top:0;">Wachtwoord resetten</h2><p>Hallo {{guest_name}},</p><p>We hebben een verzoek ontvangen om je wachtwoord te resetten voor je account bij <strong>{{tenant_name}}</strong>.</p><p>Klik op de onderstaande knop om een nieuw wachtwoord in te stellen:</p><div style="text-align:center;margin:30px 0;"><a href="{{password_reset_link}}" style="background:#FFC107;color:#000;padding:12px 30px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;display:inline-block;">Wachtwoord resetten</a></div><p style="color:#888;font-size:14px;">Deze link is 1 uur geldig. Daarna moet je een nieuwe aanvragen.</p><p style="color:#888;font-size:12px;">Als je dit verzoek niet hebt gedaan, kun je deze e-mail negeren.</p><hr style="border:none;border-top:1px solid #2a2a4a;margin:25px 0;"><p style="color:#888;font-size:13px;margin:0;">Met vriendelijke groet,<br><strong style="color:#e0e0e0;">{{tenant_name}}</strong></p></div></div></body></html>',
                     'text_content' => "Wachtwoord resetten — {{tenant_name}}\n\nHallo {{guest_name}},\n\nWe hebben een verzoek ontvangen om je wachtwoord te resetten.\n\nKlik op de onderstaande link om een nieuw wachtwoord in te stellen:\n{{password_reset_link}}\n\nDeze link is 1 uur geldig. Daarna moet je een nieuwe aanvragen.\n\nAls je dit verzoek niet hebt gedaan, kun je deze e-mail negeren.\n\nMet vriendelijke groet,\n{{tenant_name}}",
                 ],
             ];

             foreach ($templates as $tpl) {
                 // 1. UPSERT global default (tenant_id IS NULL)
                 $stmt = $db->prepare("SELECT id FROM email_templates WHERE type = :type AND tenant_id IS NULL AND language_code = 'nl'");
                 $stmt->execute([':type' => $tpl['type']]);
                 $existing = $stmt->fetch();

                 if ($existing) {
                     $stmt = $db->prepare("UPDATE email_templates SET subject = :subject, content = :content, text_content = :text_content WHERE id = :id");
                     $stmt->execute([':subject' => $tpl['subject'], ':content' => $tpl['content'], ':text_content' => $tpl['text_content'], ':id' => $existing['id']]);
                 } else {
                     $stmt = $db->prepare("INSERT INTO email_templates (type, subject, content, text_content, tenant_id, language_code, is_default) VALUES (:type, :subject, :content, :text_content, NULL, 'nl', 1)");
                     $stmt->execute([':type' => $tpl['type'], ':subject' => $tpl['subject'], ':content' => $tpl['content'], ':text_content' => $tpl['text_content']]);
                 }

                 // 2. Also overwrite ALL tenant-specific templates of this type
                 $stmt = $db->prepare("SELECT id, tenant_id FROM email_templates WHERE type = :type AND tenant_id IS NOT NULL AND language_code = 'nl'");
                 $stmt->execute([':type' => $tpl['type']]);
                 $tenantTemplates = $stmt->fetchAll();

                 foreach ($tenantTemplates as $tt) {
                     $stmt = $db->prepare("UPDATE email_templates SET subject = :subject, content = :content, text_content = :text_content WHERE id = :id");
                     $stmt->execute([':subject' => $tpl['subject'], ':content' => $tpl['content'], ':text_content' => $tpl['text_content'], ':id' => $tt['id']]);
                 }
              }
              return true;
          },
      ],
      [
          'name'   => 'Guest Already Registered Email Template',
          'file'   => 'guest_already_registered_template.sql',
          'check'  => function (PDO $db): bool {
              $stmt = $db->prepare("SELECT COUNT(*) FROM email_templates WHERE type = 'guest_already_registered' AND tenant_id IS NULL AND language_code = 'nl'");
              $stmt->execute();
              return (int) $stmt->fetchColumn() >= 1;
          },
      ],
      [
          'name'   => 'Admin Wallet Credit (performed_by, admin_reason, wallet_credit_log)',
          'file'   => 'admin_wallet_credit_migration.sql',
          'type'   => 'alter+table',
          'check'  => function (PDO $db): bool {
              return columnExists($db, 'transactions', 'performed_by')
                  && columnExists($db, 'transactions', 'admin_reason')
                  && tableExists($db, 'wallet_credit_log');
          },
      ],
    [
        'name'   => 'Bonus Cents (fixed bonus amount per package)',
        'file'   => 'bonus_cents_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'loyalty_tiers', 'bonus_cents');
        },
    ],
    [
        'name'   => 'BTW Columns (transactions)',
        'file'   => 'btw_columns_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'transactions', 'btw_alc_cents');
        },
    ],
    [
        'name'   => 'BTW Backfill existing transactions',
        'type'   => 'inline',
        'check'  => function (PDO $db): bool {
            // Only run check if the BTW columns already exist
            if (!columnExists($db, 'transactions', 'btw_alc_cents')) {
                return false; // Columns don't exist yet — not applied
            }
            // Check: are there any payment transactions with zero BTW that should have BTW?
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM `transactions`
                 WHERE `type` = 'payment'
                   AND `btw_alc_cents` = 0 AND `btw_food_cents` = 0
                   AND (`amount_alc_cents` + `amount_food_cents`) > 0"
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn() === 0;
        },
         'run'    => function (PDO $db): bool {
             // Backfill BTW for existing payment transactions
             // Alcohol: 21% | Food: 9% — berekend over netto (na korting)
             $stmt = $db->prepare(
                 "UPDATE `transactions`
                  SET 
                      `btw_alc_cents`  = FLOOR((`amount_alc_cents` - `discount_alc_cents`) / 121 * 21),
                      `btw_food_cents` = FLOOR((`amount_food_cents` - `discount_food_cents`) / 109 * 9),
                      `btw_total_cents` = FLOOR((`amount_alc_cents` - `discount_alc_cents`) / 121 * 21)
                                       + FLOOR((`amount_food_cents` - `discount_food_cents`) / 109 * 9)
                  WHERE `type` = 'payment'
                    AND `btw_alc_cents` = 0 AND `btw_food_cents` = 0
                    AND (`amount_alc_cents` + `amount_food_cents`) > 0"
             );
             $stmt->execute();
             $count = $stmt->rowCount();
             error_log("BTW Backfill: updated {$count} transactions");
             return true;
         },
     ],
     [
     'name'   => 'Email Verification (code + verified_at)',
     'file'   => 'email_verification_migration.sql',
     'type'   => 'alter',
     'check'  => function (PDO $db): bool {
     return columnExists($db, 'users', 'email_verification_code')
     && columnExists($db, 'users', 'email_verified_at');
     },
     ],
    [
        'name'   => 'Tip/Fooi Feature (tenant tip amounts + transaction tip tracking)',
        'file'   => 'tip_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'tip_amount_1_cents')
                && columnExists($db, 'transactions', 'tip_cents')
                && columnExists($db, 'pos_payment_sessions', 'tip_cents');
        },
    ],
    [
        'name'   => 'Mollie Connect Access Token per Tenant',
        'file'   => 'mollie_connect_token_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'mollie_connect_access_token');
        },
    ],
    [
        'name'   => 'Mollie Connect Profile ID per Tenant',
        'file'   => 'mollie_profile_id_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'mollie_connect_profile_id');
        },
    ],
    [
        'name'   => 'Mollie Token Refresh (refresh_token + expires_at)',
        'file'   => 'mollie_token_refresh_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'mollie_connect_refresh_token')
                && columnExists($db, 'tenants', 'mollie_connect_token_expires_at');
        },
    ],
    [
        'name'   => 'Transaction Status (deposit lifecycle: pending/paid/failed/expired/cancelled)',
        'file'   => 'transaction_status_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'transactions', 'status');
        },
    ],
    [
        'name'   => 'Emergency Token (break-glass superadmin access)',
        'type'   => 'inline',
        'check'  => function (PDO $db): bool {
            // "Applied" = there is an active (non-empty) emergency token hash
            $stmt = $db->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'emergency_token_hash' AND setting_value != '' LIMIT 1");
            $stmt->execute();
            return !empty($stmt->fetchColumn());
        },
        'run'    => function (PDO $db): bool {
            // Generate a new 256-bit emergency token
            $token = bin2hex(random_bytes(32));
            $hash  = password_hash($token, PASSWORD_DEFAULT);

            // Ensure platform_settings table exists
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `platform_settings` (
                    `id`            INT AUTO_INCREMENT PRIMARY KEY,
                    `setting_key`   VARCHAR(128)   NOT NULL UNIQUE,
                    `setting_value` TEXT           DEFAULT NULL,
                    `encrypted`     TINYINT(1)     NOT NULL DEFAULT 0,
                    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Upsert the token hash
            $stmt = $db->prepare(
                "INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`)
                 VALUES ('emergency_token_hash', :hash, 1)
                 ON DUPLICATE KEY UPDATE `setting_value` = :hash2, `updated_at` = NOW()"
            );
            $stmt->execute([':hash' => $hash, ':hash2' => $hash]);

            // Display the plaintext token (shown ONCE in migration output)
            echo "\n";
            echo bold("  ┌──────────────────────────────────────────────────────┐\n");
            echo bold("  │  🔑 EMERGENCY TOKEN GEGENEREERD                      │\n");
            echo bold("  │                                                      │\n");
            echo yellow("  │  Token: {$token}  │\n");
            echo bold("  │                                                      │\n");
            echo bold("  │  Gebruik dit token als wachtwoord op /login          │\n");
            echo bold("  │  met je superadmin e-mail. Het token is eenmalig.     │\n");
            echo bold("  └──────────────────────────────────────────────────────┘\n\n");

            return true;
        },
    ],
    [
        'name'   => 'Security Hardening (password setup tokens + Mollie webhook secret)',
        'file'   => 'security_hardening_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'users', 'password_setup_token_hash')
                && columnExists($db, 'users', 'password_setup_expires_at');
        },
    ],
    [
        'name'   => 'Mollie Webhook Secret Token',
        'type'   => 'inline',
        'check'  => function (PDO $db): bool {
            $stmt = $db->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'mollie_webhook_secret' AND setting_value != '' LIMIT 1");
            $stmt->execute();
            return !empty($stmt->fetchColumn());
        },
        'run'    => function (PDO $db): bool {
            // Generate a 256-bit webhook secret token
            $secret = bin2hex(random_bytes(32));

            $stmt = $db->prepare(
                "INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`)
                 VALUES ('mollie_webhook_secret', :secret, 0)
                 ON DUPLICATE KEY UPDATE `setting_value` = :secret2, `updated_at` = NOW()"
            );
            $stmt->execute([':secret' => $secret, ':secret2' => $secret]);

            echo "\n";
            echo bold("  ┌──────────────────────────────────────────────────────┐\n");
            echo bold("  │  🔒 MOLLIE WEBHOOK SECRET GEGENEREERD               │\n");
            echo bold("  │                                                      │\n");
            echo yellow("  │  Secret: {$secret}  │\n");
            echo bold("  │                                                      │\n");
            echo bold("  │  Configureer dit als webhook URL parameter in Mollie │\n");
            echo bold("  │  /api/mollie/webhook?token=<secret>                  │\n");
            echo bold("  └──────────────────────────────────────────────────────┘\n\n");

            return true;
        },
    ],
    [
        'name'   => 'Mollie Connect Onboarding Status Cache',
        'file'   => 'mollie_status_cache_migration.sql',
        'type'   => 'alter',
        'check'  => function (PDO $db): bool {
            return columnExists($db, 'tenants', 'mollie_connect_onboarding_status')
                && columnExists($db, 'tenants', 'mollie_connect_can_receive_payments')
                && columnExists($db, 'tenants', 'mollie_connect_status_checked_at');
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
        'wallet_credit_log',
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
            // Mollie Connect token migration
            'mollie_connect_access_token',
            // Mollie Connect profile ID migration
            'mollie_connect_profile_id',
            // Mollie token refresh migration
            'mollie_connect_refresh_token', 'mollie_connect_token_expires_at',
            // Gated onboarding migration
            'verification_soft_limit', 'verification_hard_limit',
            'verification_cooldown_sec', 'verification_max_attempts',
            // Verification toggle migration
            'verification_required',
            // Points toggle migration
            'points_enabled',
            // Test tenant migration
            'is_test',
            // Tier model lock migration
            'tier_model_type',
            // Tip migration
            'tip_amount_1_cents', 'tip_amount_2_cents', 'tip_amount_3_cents',
            // Mollie onboarding status cache migration
            'mollie_connect_onboarding_status', 'mollie_connect_can_receive_payments',
            'mollie_connect_status_checked_at',
        ],
        'users' => [
            'tenant_id', 'email', 'password_hash', 'role', 'first_name', 'last_name',
            'birthdate', 'photo_url', 'photo_status',
            // Gated onboarding migration
            'account_status', 'verified_at', 'verified_by',
            'verified_birthdate', 'suspended_reason', 'suspended_at', 'suspended_by',
            // FCM & profile columns migration (fcm_token replaces legacy push_token)
            'fcm_token', 'phone', 'street', 'house_number', 'postal_code', 'city',
            // Email verification migration
            'email_verification_code', 'email_verified_at',
            // Security hardening migration
            'password_setup_token_hash', 'password_setup_expires_at',
        ],
        'loyalty_tiers' => [
            'id', 'tenant_id', 'name', 'min_deposit_cents',
            // Package tiers migration
            'topup_amount_cents', 'alcohol_discount_perc', 'food_discount_perc',
            'points_multiplier', 'is_active', 'sort_order',
            // Model type migration
            'model_type', 'bonus_percentage',
            // Bonus cents migration
            'bonus_cents',
        ],
        'transactions' => [
            'id', 'tenant_id', 'user_id', 'type', 'final_total_cents',
            // created_at migration
            'created_at',
            // Admin wallet credit migration
            'performed_by', 'admin_reason',
            // BTW columns migration
            'btw_alc_cents', 'btw_food_cents', 'btw_total_cents',
            // Tip migration
            'tip_cents',
            // Transaction status migration
            'status',
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
