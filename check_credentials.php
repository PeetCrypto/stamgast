<?php
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT id, user_id, LENGTH(public_key) as pk_len, LEFT(public_key, 10) as pk_prefix, credential_id FROM user_credentials');
$credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Credentials found: " . count($credentials) . "\n\n";
foreach ($credentials as $cred) {
    echo "ID: " . $cred['id'] . "\n";
    echo "User ID: " . $cred['user_id'] . "\n";
    echo "Public key length: " . $cred['pk_len'] . " bytes\n";
    echo "Public key prefix (hex): " . bin2hex($cred['pk_prefix']) . "\n";
    echo "Credential ID: " . $cred['credential_id'] . "\n";
    echo "---\n";
}