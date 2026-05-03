<?php
/**
 * Test email sending to diagnose why emails aren't being sent
 */
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/EmailConfig.php';
require_once __DIR__ . '/models/EmailTemplate.php';
require_once __DIR__ . '/services/Email/EmailService.php';

$db = Database::getInstance()->getConnection();

// Check active email config
$stmt = $db->query('SELECT * FROM email_config WHERE is_active = 1 LIMIT 1');
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    echo "ERROR: No active email configuration found!" . PHP_EOL;
    exit(1);
}

echo "=== Active Email Config ===" . PHP_EOL;
echo "Provider: " . $config['provider'] . PHP_EOL;
echo "SMTP Host: " . $config['smtp_host'] . PHP_EOL;
echo "SMTP Port: " . $config['smtp_port'] . PHP_EOL;
echo "SMTP Encryption: " . ($config['smtp_encryption'] ?? 'tls') . PHP_EOL;
echo "SMTP User: " . $config['smtp_user'] . PHP_EOL;
echo "From Email: " . $config['from_email'] . PHP_EOL;
echo "From Name: " . $config['from_name'] . PHP_EOL;
echo PHP_EOL;

// Try to send a test email
echo "=== Sending Test Email ===" . PHP_EOL;

$emailService = new EmailService($db);

$testEmail = $config['from_email'];
$subject = 'REGULR.vip Test - ' . date('Y-m-d H:i:s');
$html = '<h2>Test Email</h2><p>This is a test email from REGULR.vip.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>';
$text = 'Test email from REGULR.vip sent at ' . date('Y-m-d H:i:s');

echo "Sending to: {$testEmail}" . PHP_EOL;
echo "Subject: {$subject}" . PHP_EOL;

$result = $emailService->sendEmail($testEmail, $subject, $html, $text, 'test', null, null);

if ($result) {
    echo "RESULT: SUCCESS - Email sent!" . PHP_EOL;
} else {
    echo "RESULT: FAILED - Email could not be sent." . PHP_EOL;
}

// Check email_log
echo PHP_EOL . "=== Email Log (last 5) ===" . PHP_EOL;
$stmt = $db->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 5');
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($logs)) {
    echo "No email log entries found." . PHP_EOL;
} else {
    foreach ($logs as $l) {
        echo sprintf("#%d: to=%s, status=%s, provider=%s, error=%s",
            $l['id'], $l['recipient_email'], $l['status'], $l['provider'], $l['error_message'] ?? 'none') . PHP_EOL;
    }
}
