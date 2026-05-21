<?php
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

// Get current user
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    die("No user logged in. Access this file while logged in as guest.");
}

echo "Deleting credentials for user $userId...\n";

$stmt = $db->prepare('DELETE FROM user_credentials WHERE user_id = ?');
$stmt->execute([$userId]);

echo "Deleted " . $stmt->rowCount() . " credentials.\n";
echo "\nFace ID has been reset. You can now re-register from your profile.";