<?php
// Standalone script to delete all user_credentials
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

// Delete all credentials (for debugging)
$stmt = $db->query('DELETE FROM user_credentials');
$count = $stmt->rowCount();

echo "Deleted $count credentials from user_credentials table.\n";

// Also delete all challenges
$stmt2 = $db->query('DELETE FROM webauthn_challenges');
$count2 = $stmt2->rowCount();
echo "Deleted $count2 challenges from webauthn_challenges table.\n";

echo "\nDone! Face ID has been fully reset. Please refresh your profile page.";