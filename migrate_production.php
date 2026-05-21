<?php
/**
 * REGULR.vip — Productie Migratie Script
 * 
 * Eén bestand. Open in browser. Klaar.
 * 
 * Gebruik:  https://app.regulr.vip/migrate_production.php
 * Lokaal:   http://stamgast.test/migrate_production.php
 * 
 * - Creëert alle tabellen (IF NOT EXISTS)
 * - Voegt alle kolommen toe (alleen als ze nog niet bestaan)
 * - 100% idempotent: veilig om meerdere keren te draaien
 * - Geen externe SQL bestanden nodig
 */

declare(strict_types=1);

// ── Laad .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $m)) $value = $m[1];
        putenv("$key=$value");
    }
}

// ── Database connectie ───────────────────────────────────────────────────────
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'stamgast_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $db = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('DB connectie gefaald: ' . $e->getMessage());
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function colExists(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function addCol(PDO $db, string $table, string $col, string $def): void {
    global $log;
    if (colExists($db, $table, $col)) {
        $log[] = ['ok', " Kolom <b>{$table}.{$col}</b> bestaat al"];
        return;
    }
    $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
    $log[] = ['add', " Kolom <b>{$table}.{$col}</b> toegevoegd"];
}

function createTable(PDO $db, string $table, string $sql): void {
    global $log;
    if (tableExists($db, $table)) {
        $log[] = ['ok', " Tabel <b>{$table}</b> bestaat al"];
        return;
    }
    $db->exec($sql);
    $log[] = ['add', " Tabel <b>{$table}</b> aangemaakt"];
}

function addIndex(PDO $db, string $table, string $indexName, string $sql): void {
    global $log;
    try {
        $db->exec($sql);
        $log[] = ['add', " Index <b>{$indexName}</b> toegevoegd op {$table}"];
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $log[] = ['ok', " Index <b>{$indexName}</b> bestaat al"];
        } else {
            $log[] = ['warn', " Index {$indexName}: " . $e->getMessage()];
        }
    }
}

// ── Start output ─────────────────────────────────────────────────────────────
$log = [];
$db->exec("SET NAMES utf8mb4");
$db->exec("SET FOREIGN_KEY_CHECKS = 0");

?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>REGULR.vip — Migratie</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Consolas, monospace; background: #0d1117; color: #c9d1d9; padding: 24px; }
h1 { color: #e6edf3; margin-bottom: 8px; font-size: 20px; }
.sub { color: #484f58; margin-bottom: 24px; font-size: 13px; }
.log { font-size: 13px; line-height: 2; }
.ok { color: #3fb950; }
.add { color: #58a6ff; }
.warn { color: #d29922; }
.err { color: #f85149; }
b { color: #e6edf3; }
.summary { margin-top: 24px; padding: 16px; border: 1px solid #30363d; border-radius: 8px; font-size: 14px; }
</style>
</head>
<body>
<h1>REGULR.vip — Database Migratie</h1>
<p class="sub"><?= $dbUser ?>@<?= $dbHost ?>:<?= $dbPort ?>/<?= $dbName ?> • <?= date('Y-m-d H:i:s') ?></p>
<div class="log">
<?php

// ══════════════════════════════════════════════════════════════════════════════
// TABELLEN AANMAKEN
// ══════════════════════════════════════════════════════════════════════════════

echo "<br><b>── TABELLEN ──</b><br>";

createTable($db, 'tenants', "CREATE TABLE IF NOT EXISTS `tenants` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `brand_color` VARCHAR(7) DEFAULT '#FFC107',
    `secondary_color` VARCHAR(7) DEFAULT '#FF9800',
    `logo_path` VARCHAR(255) NULL,
    `secret_key` VARCHAR(64) NOT NULL,
    `mollie_api_key` VARCHAR(255) NULL,
    `mollie_status` ENUM('mock','test','live') DEFAULT 'mock',
    `whitelisted_ips` TEXT NULL,
    `verification_soft_limit` INT NOT NULL DEFAULT 15,
    `verification_hard_limit` INT NOT NULL DEFAULT 30,
    `verification_cooldown_sec` INT NOT NULL DEFAULT 180,
    `verification_max_attempts` INT NOT NULL DEFAULT 2,
    `contact_name` VARCHAR(255) NULL,
    `contact_email` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `address` VARCHAR(255) NULL,
    `postal_code` VARCHAR(20) NULL,
    `city` VARCHAR(100) NULL,
    `country` VARCHAR(100) DEFAULT 'Nederland',
    `is_active` BOOLEAN DEFAULT 1,
    `feature_push` BOOLEAN DEFAULT 1,
    `feature_marketing` BOOLEAN DEFAULT 1,
    `points_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `platform_fee_percentage` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    `platform_fee_min_cents` INT NOT NULL DEFAULT 25,
    `mollie_connect_id` VARCHAR(255) NULL,
    `mollie_connect_status` ENUM('none','pending','active','suspended','revoked') NOT NULL DEFAULT 'none',
    `invoice_period` ENUM('week','month') NOT NULL DEFAULT 'month',
    `btw_number` VARCHAR(50) NULL,
    `invoice_email` VARCHAR(255) NULL,
    `platform_fee_note` TEXT NULL,
    `verification_required` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenants_uuid` (`uuid`),
    UNIQUE KEY `uk_tenants_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'users', "CREATE TABLE IF NOT EXISTS `users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('superadmin','admin','bartender','guest') NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `birthdate` DATE NULL,
    `photo_url` VARCHAR(255) NULL,
    `photo_status` ENUM('unvalidated','validated','blocked') DEFAULT 'unvalidated',
    `account_status` ENUM('unverified','active','suspended') NOT NULL DEFAULT 'unverified',
    `verified_at` TIMESTAMP NULL,
    `verified_by` INT NULL,
    `verified_birthdate` DATE NULL,
    `suspended_reason` VARCHAR(500) NULL,
    `suspended_at` TIMESTAMP NULL,
    `suspended_by` INT NULL,
    `fcm_token` TEXT NULL,
    `phone` VARCHAR(20) NULL,
    `street` VARCHAR(100) NULL,
    `house_number` VARCHAR(10) NULL,
    `postal_code` VARCHAR(10) NULL,
    `city` VARCHAR(50) NULL,
    `last_activity` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_tenant_email` (`tenant_id`, `email`),
    INDEX `idx_users_tenant` (`tenant_id`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_account_status` (`account_status`),
    CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'wallets', "CREATE TABLE IF NOT EXISTS `wallets` (
    `user_id` INT NOT NULL,
    `tenant_id` INT NOT NULL,
    `balance_cents` BIGINT DEFAULT 0,
    `points_cents` BIGINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    INDEX `idx_wallets_tenant` (`tenant_id`),
    CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wallets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'loyalty_tiers', "CREATE TABLE IF NOT EXISTS `loyalty_tiers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `min_deposit_cents` BIGINT DEFAULT 0,
    `topup_amount_cents` INT NOT NULL DEFAULT 10000,
    `model_type` ENUM('discount','bonus') NOT NULL DEFAULT 'discount',
    `bonus_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `alcohol_discount_perc` DECIMAL(5,2) DEFAULT 0.00,
    `food_discount_perc` DECIMAL(5,2) DEFAULT 0.00,
    `points_multiplier` DECIMAL(3,2) DEFAULT 1.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tiers_tenant` (`tenant_id`),
    INDEX `idx_model_type` (`tenant_id`, `model_type`),
    CONSTRAINT `fk_tiers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'transactions', "CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NULL,
    `user_id` INT NOT NULL,
    `bartender_id` INT NULL,
    `type` ENUM('payment','deposit','bonus','correction') NOT NULL,
    `amount_alc_cents` INT DEFAULT 0,
    `amount_food_cents` INT DEFAULT 0,
    `discount_alc_cents` INT DEFAULT 0,
    `discount_food_cents` INT DEFAULT 0,
    `final_total_cents` INT NOT NULL,
    `points_earned` INT DEFAULT 0,
    `points_used` INT DEFAULT 0,
    `ip_address` VARCHAR(45) NOT NULL,
    `device_fingerprint` VARCHAR(255) NULL,
    `mollie_payment_id` VARCHAR(255) NULL,
    `description` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_trans_tenant` (`tenant_id`),
    INDEX `idx_trans_user` (`user_id`),
    INDEX `idx_trans_bartender` (`bartender_id`),
    INDEX `idx_trans_type` (`type`),
    INDEX `idx_trans_created` (`created_at`),
    CONSTRAINT `fk_trans_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_trans_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_trans_bartender` FOREIGN KEY (`bartender_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'push_subscriptions', "CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `endpoint` TEXT NOT NULL,
    `p256dh` TEXT NOT NULL,
    `auth` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_push_tenant` (`tenant_id`),
    INDEX `idx_push_user` (`user_id`),
    CONSTRAINT `fk_push_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'email_queue', "CREATE TABLE IF NOT EXISTS `email_queue` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_html` TEXT NOT NULL,
    `status` ENUM('pending','sent','failed') DEFAULT 'pending',
    `sent_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email_tenant` (`tenant_id`),
    INDEX `idx_email_status` (`status`),
    CONSTRAINT `fk_email_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'audit_log', "CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NULL,
    `user_id` INT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NULL,
    `entity_id` INT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_tenant` (`tenant_id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'password_resets', "CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `tenant_id` INT NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token` (`token`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_email_tenant` (`email`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'notifications', "CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `transaction_id` INT NULL,
    `type` VARCHAR(50) NOT NULL,
    `icon` VARCHAR(10) NOT NULL DEFAULT '📋',
    `title` VARCHAR(255) NOT NULL,
    `body` VARCHAR(500) NOT NULL,
    `color` VARCHAR(100) NOT NULL DEFAULT 'var(--text-secondary)',
    `points_earned` INT NOT NULL DEFAULT 0,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notif_tenant` (`tenant_id`),
    INDEX `idx_notif_user` (`user_id`),
    INDEX `idx_notif_user_read` (`user_id`, `is_read`),
    INDEX `idx_notif_deleted` (`deleted_at`),
    INDEX `idx_notif_transaction` (`transaction_id`),
    CONSTRAINT `fk_notif_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'email_config', "CREATE TABLE IF NOT EXISTS `email_config` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `provider` ENUM('brevo','sender_net','aws_ses') NOT NULL DEFAULT 'brevo',
    `smtp_host` VARCHAR(255) NOT NULL,
    `smtp_port` INT NOT NULL DEFAULT 587,
    `smtp_encryption` ENUM('tls','ssl','starttls','none') NOT NULL DEFAULT 'tls',
    `smtp_user` TEXT NOT NULL,
    `smtp_pass` TEXT NOT NULL,
    `from_email` VARCHAR(255) NOT NULL DEFAULT 'no-reply@regulr.vip',
    `from_name` VARCHAR(255) NOT NULL DEFAULT 'REGULR.vip',
    `is_active` BOOLEAN NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'email_templates', "CREATE TABLE IF NOT EXISTS `email_templates` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `type` ENUM('tenant_welcome','admin_invite','bartender_invite','guest_confirmation','guest_password_reset','marketing') NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `text_content` TEXT NULL,
    `tenant_id` INT NULL,
    `language_code` VARCHAR(10) NOT NULL DEFAULT 'nl',
    `is_default` BOOLEAN NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email_tpl_type` (`type`),
    INDEX `idx_email_tpl_tenant` (`tenant_id`),
    CONSTRAINT `fk_email_tpl_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'email_log', "CREATE TABLE IF NOT EXISTS `email_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NULL,
    `user_id` INT NULL,
    `recipient_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `template_type` VARCHAR(100) NULL,
    `template_id` INT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `sent_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_elog_tenant` (`tenant_id`),
    INDEX `idx_elog_status` (`status`),
    INDEX `idx_elog_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'platform_invoices', "CREATE TABLE IF NOT EXISTS `platform_invoices` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `invoice_number` VARCHAR(50) NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `period_type` ENUM('week','month') NOT NULL,
    `transaction_count` INT NOT NULL DEFAULT 0,
    `gross_total_cents` BIGINT NOT NULL DEFAULT 0,
    `fee_total_cents` BIGINT NOT NULL DEFAULT 0,
    `btw_percentage` DECIMAL(5,2) NOT NULL DEFAULT 21.00,
    `btw_amount_cents` BIGINT NOT NULL DEFAULT 0,
    `total_incl_btw_cents` BIGINT NOT NULL DEFAULT 0,
    `status` ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    `pdf_path` VARCHAR(255) NULL,
    `sent_at` TIMESTAMP NULL,
    `paid_at` TIMESTAMP NULL,
    `cancelled_at` TIMESTAMP NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pi_number` (`invoice_number`),
    INDEX `idx_pi_tenant` (`tenant_id`),
    INDEX `idx_pi_status` (`status`),
    INDEX `idx_pi_period` (`period_start`, `period_end`),
    CONSTRAINT `fk_pi_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'platform_fees', "CREATE TABLE IF NOT EXISTS `platform_fees` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `transaction_id` INT NOT NULL,
    `mollie_payment_id` VARCHAR(255) NULL,
    `user_id` INT NOT NULL,
    `gross_amount_cents` INT NOT NULL,
    `fee_percentage` DECIMAL(5,2) NOT NULL,
    `fee_amount_cents` INT NOT NULL,
    `net_amount_cents` INT NOT NULL,
    `fee_min_cents` INT NOT NULL DEFAULT 0,
    `mollie_fee_cents` INT NULL,
    `mollie_settlement_id` VARCHAR(255) NULL,
    `status` ENUM('collected','invoiced','settled') NOT NULL DEFAULT 'collected',
    `invoice_id` INT NULL,
    `deposit_processed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pf_transaction` (`transaction_id`),
    INDEX `idx_pf_tenant` (`tenant_id`),
    INDEX `idx_pf_status` (`status`),
    INDEX `idx_pf_created` (`created_at`),
    CONSTRAINT `fk_pf_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `platform_invoices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'platform_fee_log', "CREATE TABLE IF NOT EXISTS `platform_fee_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `platform_fee_id` INT NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `old_value` VARCHAR(255) NULL,
    `new_value` VARCHAR(255) NULL,
    `actor_user_id` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_pfl_fee` (`platform_fee_id`),
    INDEX `idx_pfl_action` (`action`),
    CONSTRAINT `fk_pfl_fee` FOREIGN KEY (`platform_fee_id`) REFERENCES `platform_fees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

createTable($db, 'platform_settings', "CREATE TABLE IF NOT EXISTS `platform_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(128) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed platform settings
try {
    $db->exec("INSERT IGNORE INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`) VALUES
        ('mollie_connect_api_key', '', 1),
        ('mollie_connect_client_id', '', 0),
        ('mollie_connect_client_secret', '', 1),
        ('mollie_mode_default', 'mock', 0)");
    $log[] = ['ok', " Platform settings seeded"];
} catch (PDOException $e) {
    $log[] = ['ok', " Platform settings al aanwezig"];
}

createTable($db, 'verification_attempts', "CREATE TABLE IF NOT EXISTS `verification_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `verified_by` INT NOT NULL,
    `birthdate_seen` DATE NOT NULL,
    `birthdate_match` TINYINT(1) NOT NULL,
    `status_before` ENUM('unverified','active','suspended') NOT NULL,
    `status_after` ENUM('unverified','active','suspended') NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `notes` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant_id` (`tenant_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_verified_by` (`verified_by`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_verif_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_verif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_verif_by` FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// ══════════════════════════════════════════════════════════════════════════════
// KOLOMMEN TOEVOEGEN (voor bestaande tabellen die nog niet alle kolommen hebben)
// ══════════════════════════════════════════════════════════════════════════════

echo "<br><b>── KOLOMMEN ──</b><br>";

// tenants
addCol($db, 'tenants', 'verification_soft_limit',    'INT NOT NULL DEFAULT 15 AFTER `whitelisted_ips`');
addCol($db, 'tenants', 'verification_hard_limit',     'INT NOT NULL DEFAULT 30 AFTER `verification_soft_limit`');
addCol($db, 'tenants', 'verification_cooldown_sec',   'INT NOT NULL DEFAULT 180 AFTER `verification_hard_limit`');
addCol($db, 'tenants', 'verification_max_attempts',   'INT NOT NULL DEFAULT 2 AFTER `verification_cooldown_sec`');
addCol($db, 'tenants', 'platform_fee_percentage',     "DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Platform fee percentage'");
addCol($db, 'tenants', 'platform_fee_min_cents',      "INT NOT NULL DEFAULT 25 COMMENT 'Minimum fee in cents'");
addCol($db, 'tenants', 'mollie_connect_id',           'VARCHAR(255) NULL');
addCol($db, 'tenants', 'mollie_connect_status',       "ENUM('none','pending','active','suspended','revoked') NOT NULL DEFAULT 'none'");
addCol($db, 'tenants', 'invoice_period',              "ENUM('week','month') NOT NULL DEFAULT 'month'");
addCol($db, 'tenants', 'btw_number',                  'VARCHAR(50) NULL');
addCol($db, 'tenants', 'invoice_email',               'VARCHAR(255) NULL');
addCol($db, 'tenants', 'platform_fee_note',           'TEXT NULL');
addCol($db, 'tenants', 'verification_required',       'TINYINT(1) NOT NULL DEFAULT 1');
addCol($db, 'tenants', 'points_enabled',              'TINYINT(1) NOT NULL DEFAULT 1 AFTER `feature_marketing`');

// users
addCol($db, 'users', 'account_status',      "ENUM('unverified','active','suspended') NOT NULL DEFAULT 'unverified' AFTER `photo_status`");
addCol($db, 'users', 'verified_at',         'TIMESTAMP NULL AFTER `account_status`');
addCol($db, 'users', 'verified_by',         'INT NULL AFTER `verified_at`');
addCol($db, 'users', 'verified_birthdate',  'DATE NULL AFTER `verified_by`');
addCol($db, 'users', 'suspended_reason',    'VARCHAR(500) NULL AFTER `verified_birthdate`');
addCol($db, 'users', 'suspended_at',        'TIMESTAMP NULL AFTER `suspended_reason`');
addCol($db, 'users', 'suspended_by',        'INT NULL AFTER `suspended_at`');
addCol($db, 'users', 'fcm_token',           'TEXT NULL');
addCol($db, 'users', 'phone',               'VARCHAR(20) NULL');
addCol($db, 'users', 'street',              'VARCHAR(100) NULL');
addCol($db, 'users', 'house_number',        'VARCHAR(10) NULL');
addCol($db, 'users', 'postal_code',         'VARCHAR(10) NULL');
addCol($db, 'users', 'city',                'VARCHAR(50) NULL');

// loyalty_tiers
addCol($db, 'loyalty_tiers', 'topup_amount_cents',    'INT NOT NULL DEFAULT 10000 AFTER `min_deposit_cents`');
addCol($db, 'loyalty_tiers', 'model_type',            "ENUM('discount','bonus') NOT NULL DEFAULT 'discount' AFTER `topup_amount_cents`");
addCol($db, 'loyalty_tiers', 'bonus_percentage',      'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `model_type`');
addCol($db, 'loyalty_tiers', 'is_active',             'TINYINT(1) NOT NULL DEFAULT 1 AFTER `points_multiplier`');
addCol($db, 'loyalty_tiers', 'sort_order',            'INT NOT NULL DEFAULT 0 AFTER `is_active`');
addCol($db, 'loyalty_tiers', 'updated_at',            'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

// transactions
addCol($db, 'transactions', 'created_at',             'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

// Indexes
addIndex($db, 'users', 'idx_account_status',          'ALTER TABLE `users` ADD INDEX `idx_account_status` (`account_status`)');
addIndex($db, 'users', 'idx_verified_by',             'ALTER TABLE `users` ADD INDEX `idx_verified_by` (`verified_by`)');
addIndex($db, 'loyalty_tiers', 'idx_model_type',      'ALTER TABLE `loyalty_tiers` ADD INDEX `idx_model_type` (`tenant_id`, `model_type`)');
addIndex($db, 'platform_fees', 'idx_pf_tenant_status', 'CREATE INDEX `idx_pf_tenant_status` ON `platform_fees` (`tenant_id`, `status`, `created_at`)');


// ══════════════════════════════════════════════════════════════════════════════
// OUTPUT LOG
// ══════════════════════════════════════════════════════════════════════════════

$created = 0;
$existing = 0;
foreach ($log as $entry) {
    [$type, $msg] = $entry;
    echo "<span class=\"{$type}\">{$msg}</span><br>";
    if ($type === 'add') $created++;
    if ($type === 'ok') $existing++;
}

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICATIE
// ══════════════════════════════════════════════════════════════════════════════

$db->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "<br><b>── VERIFICATIE ──</b><br>";

$requiredTables = [
    'tenants','users','wallets','loyalty_tiers','transactions',
    'push_subscriptions','email_queue','audit_log','password_resets',
    'notifications','email_config','email_templates','email_log',
    'platform_invoices','platform_fees','platform_fee_log',
    'platform_settings','verification_attempts',
];

$allOk = true;
foreach ($requiredTables as $t) {
    if (tableExists($db, $t)) {
        echo "<span class=\"ok\">✓ {$t}</span><br>";
    } else {
        echo "<span class=\"err\">✗ {$t} ONTBREEKT!</span><br>";
        $allOk = false;
    }
}

$requiredCols = [
    'tenants' => ['verification_required','points_enabled','platform_fee_percentage','verification_soft_limit'],
    'users' => ['account_status','fcm_token','phone','street','verified_at'],
    'loyalty_tiers' => ['topup_amount_cents','model_type','bonus_percentage','is_active'],
    'transactions' => ['created_at'],
];

foreach ($requiredCols as $table => $cols) {
    foreach ($cols as $col) {
        if (colExists($db, $table, $col)) {
            echo "<span class=\"ok\">✓ {$table}.{$col}</span><br>";
        } else {
            echo "<span class=\"err\">✗ {$table}.{$col} ONTBREEKT!</span><br>";
            $allOk = false;
        }
    }
}

echo "<br>";
echo "<div class=\"summary\">";
if ($allOk) {
    echo "<span class=\"ok\">✓ Alles OK — database schema is compleet en up-to-date.</span><br>";
} else {
    echo "<span class=\"err\">✗ Sommige tabellen/kolommen ontbreken. Check errors hierboven.</span><br>";
}
echo "<span class=\"ok\">{$created} toegevoegd</span> • <span class=\"ok\">{$existing} al aanwezig</span>";
echo "</div>";

?>
</div>
</body>
</html>
