<?php
declare(strict_types=1);

/**
 * Emergency Token Generator (Break-Glass Access)
 * 
 * Usage: php emergency_token.php
 * 
 * Generates a cryptographically strong emergency token for superadmin access.
 * The token hash is stored in the database (platform_settings).
 * The plaintext token is shown ONCE — save it securely.
 * 
 * After use, the token is automatically invalidated.
 * Run this script again to generate a new token.
 */

require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

echo "═══════════════════════════════════════════════════\n";
echo "  REGULR.vip — Emergency Token Generator\n";
echo "═══════════════════════════════════════════════════\n\n";

// Generate a 256-bit token (64 hex chars)
$token = bin2hex(random_bytes(32));

// Hash with Argon2id if available, fallback to bcrypt
$hashAlgo = PASSWORD_DEFAULT;
$hash = password_hash($token, $hashAlgo);

if ($hash === false) {
    echo "ERROR: Failed to hash token.\n";
    exit(1);
}

// Store hash in database
try {
    $db = Database::getInstance()->getConnection();

    // Ensure platform_settings table exists (uses existing schema: setting_key/setting_value)
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

    // Upsert the emergency token hash
    $stmt = $db->prepare(
        "INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `encrypted`)
         VALUES ('emergency_token_hash', :hash, 1)
         ON DUPLICATE KEY UPDATE `setting_value` = :hash2, `updated_at` = NOW()"
    );
    $stmt->execute([':hash' => $hash, ':hash2' => $hash]);

    echo "✅ Emergency token generated and stored.\n\n";
    echo "═══════════════════════════════════════════════════\n";
    echo "  TOKEN (save this — it will not be shown again):\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "  {$token}\n\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "Usage:\n";
    echo "  1. Go to /login\n";
    echo "  2. Enter your superadmin email\n";
    echo "  3. Paste the token as the password\n";
    echo "  4. You will be logged in and forced to set a new password\n";
    echo "  5. The token is automatically invalidated after use\n\n";
    echo "To generate a new token, run this script again.\n";
    echo "═══════════════════════════════════════════════════\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
