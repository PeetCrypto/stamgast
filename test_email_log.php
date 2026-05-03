<?php
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

echo "=== email_log table structure ===" . PHP_EOL;
$stmt = $db->query("DESCRIBE email_log");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("  %s: %s %s %s", $row['Field'], $row['Type'], $row['Null'], $row['Default'] ?? '') . PHP_EOL;
}

echo PHP_EOL . "=== Try direct insert ===" . PHP_EOL;
try {
    $stmt = $db->prepare("
        INSERT INTO email_log 
            (tenant_id, user_id, recipient_email, subject, template_type, provider, status, error_message, sent_at)
        VALUES 
            (NULL, NULL, 'test@test.com', 'Test', 'test', 'manual', 'failed', 'test error', NOW())
    ");
    $result = $stmt->execute();
    echo "Insert result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;
    echo "Last insert ID: " . $db->lastInsertId() . PHP_EOL;
} catch (Throwable $e) {
    echo "Insert error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Check email_log entries ===" . PHP_EOL;
$stmt = $db->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 3');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
