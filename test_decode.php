<?php
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT public_key FROM user_credentials LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("No credentials found");
}

$pubKeyB64url = $row['public_key'];
echo "Stored public_key (base64url): " . strlen($pubKeyB64url) . " bytes\n";
echo "First 20 chars: " . substr($pubKeyB64url, 0, 20) . "\n";

// Decode
$padded = str_pad(strtr($pubKeyB64url, '-_', '+/'), strlen($pubKeyB64url) % 4, '=', STR_PAD_RIGHT);
$decoded = base64_decode($padded);

echo "\nDecoded public_key: " . strlen($decoded) . " bytes\n";
echo "First byte (hex): 0x" . bin2hex(substr($decoded, 0, 1)) . "\n";
echo "Should be 0x04 for EC P-256\n";

if (strlen($decoded) === 65 && $decoded[0] === "\x04") {
    echo "\n✅ PUBLIC KEY IS CORRECT!\n";
} else {
    echo "\n❌ PUBLIC KEY IS WRONG!\n";
    echo "Length: " . strlen($decoded) . " (expected 65)\n";
    echo "First byte: 0x" . bin2hex($decoded[0]) . " (expected 0x04)\n";
}