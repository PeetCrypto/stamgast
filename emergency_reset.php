<?php
declare(strict_types=1);

/**
 * Emergency Superadmin Password Reset (CLI only)
 * 
 * Usage: php emergency_reset.php
 * 
 * Resets the superadmin password to a new random value.
 * The new password is shown ONCE in the console.
 * 
 * SECURITY:
 * - This script MUST be run from CLI only (not via browser)
 * - Run php emergency_token.php for token-based access (preferred)
 */

// Block browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('403 — This script can only be run from the command line.');
}

require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

echo "═══════════════════════════════════════════════════\n";
echo "  REGULR.vip — Emergency Password Reset\n";
echo "═══════════════════════════════════════════════════\n\n";

$email = getenv('SUPERADMIN_EMAIL') ?: 'admin@stamgast.nl';

try {
    $db = Database::getInstance()->getConnection();

    // Verify superadmin exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email AND role = 'superadmin' LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "❌ Superadmin account not found ({$email}).\n";
        echo "   Run create_admin.php first to create the account.\n";
        exit(1);
    }

    // Generate new strong password
    $password = bin2hex(random_bytes(16));
    $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
    // Use Argon2id if available, fallback to bcrypt
    $hashAlgo = PASSWORD_DEFAULT;
    $hash = password_hash($pepperedPassword, $hashAlgo);

    if ($hash === false) {
        echo "ERROR: Failed to hash password.\n";
        exit(1);
    }

    // Update password
    $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id AND role = 'superadmin'");
    $stmt->execute([':hash' => $hash, ':id' => $user['id']]);

    // Also invalidate any emergency token
    $stmt = $db->prepare("UPDATE `platform_settings` SET `setting_value` = '' WHERE `setting_key` = 'emergency_token_hash'");
    $stmt->execute();

    echo "✅ Password reset successfully!\n\n";
    echo "═══════════════════════════════════════════════════\n";
    echo "  NEW PASSWORD (save this — shown ONCE):\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "  Email:    {$email}\n";
    echo "  Password: {$password}\n\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "Login at /login with these credentials.\n";
    echo "Change the password immediately after login.\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
