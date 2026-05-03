<?php
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

// Check current ENUM values
$stmt = $db->query("SHOW COLUMNS FROM email_templates WHERE Field = 'type'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Current ENUM: " . $col['Type'] . PHP_EOL;

// Check if email_queue table exists
try {
    $db->query('SELECT 1 FROM email_queue LIMIT 1');
    echo "email_queue table: EXISTS" . PHP_EOL;
} catch (Exception $e) {
    echo "email_queue table: MISSING - " . $e->getMessage() . PHP_EOL;
}

// Check if there are any templates
$stmt = $db->query('SELECT COUNT(*) FROM email_templates');
echo "Template count: " . $stmt->fetchColumn() . PHP_EOL;

// Show all templates
$stmt = $db->query('SELECT id, type, subject, tenant_id, language_code FROM email_templates ORDER BY type');
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($templates) {
    echo PHP_EOL . "Existing templates:" . PHP_EOL;
    foreach ($templates as $t) {
        echo sprintf("  #%d: type=%s, subject=%s, tenant_id=%s, lang=%s",
            $t['id'], $t['type'], $t['subject'], $t['tenant_id'] ?? 'NULL', $t['language_code']) . PHP_EOL;
    }
}

// Check if there's an active email config
$stmt = $db->query('SELECT COUNT(*) FROM email_config WHERE is_active = 1');
echo PHP_EOL . "Active email configs: " . $stmt->fetchColumn() . PHP_EOL;

// Check email_log for recent entries
$stmt = $db->query('SELECT COUNT(*) FROM email_log');
echo "Email log entries: " . $stmt->fetchColumn() . PHP_EOL;

// Show last 5 email log entries
$stmt = $db->query('SELECT id, recipient_email, subject, template_type, status, error_message, sent_at FROM email_log ORDER BY id DESC LIMIT 5');
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($logs) {
    echo PHP_EOL . "Last 5 email log entries:" . PHP_EOL;
    foreach ($logs as $l) {
        echo sprintf("  #%d: to=%s, subject=%s, type=%s, status=%s, error=%s",
            $l['id'], $l['recipient_email'], $l['subject'], $l['template_type'] ?? 'null', $l['status'], $l['error_message'] ?? 'null') . PHP_EOL;
    }
}
