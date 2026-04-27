<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/EmailConfig.php';

$db = Database::getInstance()->getConnection();
$emailConfig = new EmailConfig($db);

// Test INSERT (no existing config)
$testData = [
    'id'              => null,
    'provider'        => 'brevo',
    'smtp_host'       => 'smtp-relay.brevo.com',
    'smtp_port'       => 587,
    'smtp_encryption' => 'tls',
    'smtp_user'       => 'test@test.com',
    'smtp_pass'       => 'testpass123',
    'from_email'      => 'no-reply@regulr.vip',
    'from_name'       => 'STAMGAST',
    'is_active'       => 1,
];

try {
    $result = $emailConfig->saveConfig($testData);
    echo "INSERT result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
} catch (\Throwable $e) {
    echo "INSERT ERROR: " . $e->getMessage() . "\n";
}

// Check what's in the table now
$active = $emailConfig->getActiveConfig();
echo "\nActive config after INSERT:\n";
echo json_encode($active, JSON_PRETTY_PRINT) . "\n";

// Test UPDATE (with existing config, no password change)
if ($active) {
    $updateData = [
        'id'              => $active['id'],
        'provider'        => 'brevo',
        'smtp_host'       => 'smtp-relay.brevo.com',
        'smtp_port'       => 587,
        'smtp_encryption' => 'ssl',
        'smtp_user'       => 'updated@test.com',
        // smtp_pass intentionally omitted — should keep existing
        'from_email'      => 'no-reply@regulr.vip',
        'from_name'       => 'STAMGAST UPDATED',
        'is_active'       => 1,
    ];
    unset($updateData['smtp_pass']);

    try {
        $result2 = $emailConfig->saveConfig($updateData);
        echo "\nUPDATE result (no pass): " . ($result2 ? 'SUCCESS' : 'FAILED') . "\n";
    } catch (\Throwable $e) {
        echo "UPDATE ERROR: " . $e->getMessage() . "\n";
    }

    $updated = $emailConfig->getActiveConfig();
    echo "\nActive config after UPDATE:\n";
    echo json_encode($updated, JSON_PRETTY_PRINT) . "\n";
}
