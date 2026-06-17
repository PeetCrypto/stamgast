<?php
declare(strict_types=1);

/**
 * Superadmin Account Creator (CLI only)
 * 
 * Usage: php create_admin.php
 * 
 * Creates the superadmin account if it does not exist.
 * If the account already exists, shows a message.
 * 
 * SECURITY:
 * - This script MUST be run from CLI only (not via browser)
 * - Generated password is shown ONCE in the console
 * - Delete this file after initial setup on production
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
echo "  REGULR.vip — Superadmin Account Setup\n";
echo "═══════════════════════════════════════════════════\n\n";

$email = getenv('SUPERADMIN_EMAIL') ?: 'admin@stamgast.nl';

try {
    $db = Database::getInstance()->getConnection();

    // Check if superadmin already exists
    $stmt = $db->prepare("SELECT id, email FROM users WHERE email = :email AND role = 'superadmin' LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "✅ Superadmin account already exists.\n";
        echo "   Email: {$existing['email']}\n";
        echo "   ID: {$existing['id']}\n\n";
        echo "To reset the password, use: php emergency_token.php\n";
        echo "Then login with the emergency token and set a new password.\n";
        exit(0);
    }

    // Generate a strong random password
    $password = bin2hex(random_bytes(16)); // 32-char hex string
    $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
    $passwordHash = password_hash($pepperedPassword, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        echo "ERROR: Failed to hash password.\n";
        exit(1);
    }

    // Create the superadmin user
    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, role, first_name, last_name, tenant_id, account_status)
         VALUES (:email, :password_hash, :role, :first_name, :last_name, NULL, 'active')"
    );

    $stmt->execute([
        ':email'         => $email,
        ':password_hash' => $passwordHash,
        ':role'          => 'superadmin',
        ':first_name'    => 'Admin',
        ':last_name'     => 'REGULR.vip',
    ]);

    $userId = $db->lastInsertId();

    echo "✅ Superadmin account created successfully!\n\n";
    echo "═══════════════════════════════════════════════════\n";
    echo "  CREDENTIALS (save these — shown ONCE):\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "  Email:    {$email}\n";
    echo "  Password: {$password}\n\n";
    echo "═══════════════════════════════════════════════════\n\n";
    echo "IMPORTANT:\n";
    echo "  1. Save these credentials in a password manager\n";
    echo "  2. Login at /login and change the password immediately\n";
    echo "  3. Delete this file after setup: create_admin.php\n";
    echo "  4. For future recovery, use: php emergency_token.php\n\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
