<?php
/**
 * Fix email_log table to match the code expectations
 * Add tenant_id and user_id columns, change id to INT AUTO_INCREMENT
 */
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

echo "=== Fixing email_log table ===" . PHP_EOL;

// Check current columns
$stmt = $db->query("DESCRIBE email_log");
$columns = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $columns[$row['Field']] = $row['Type'];
}

echo "Current columns: " . implode(', ', array_keys($columns)) . PHP_EOL;

// Add missing columns
if (!isset($columns['tenant_id'])) {
    try {
        $db->exec("ALTER TABLE email_log ADD COLUMN tenant_id INT NULL AFTER id");
        echo "[OK] Added tenant_id column" . PHP_EOL;
    } catch (Throwable $e) {
        echo "[ERROR] Adding tenant_id: " . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "[SKIP] tenant_id already exists" . PHP_EOL;
}

if (!isset($columns['user_id'])) {
    try {
        $db->exec("ALTER TABLE email_log ADD COLUMN user_id INT NULL AFTER tenant_id");
        echo "[OK] Added user_id column" . PHP_EOL;
    } catch (Throwable $e) {
        echo "[ERROR] Adding user_id: " . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "[SKIP] user_id already exists" . PHP_EOL;
}

echo PHP_EOL . "=== Updated table structure ===" . PHP_EOL;
$stmt = $db->query("DESCRIBE email_log");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("  %s: %s %s", $row['Field'], $row['Type'], $row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . PHP_EOL;
}
