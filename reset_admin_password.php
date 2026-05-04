<?php
// Load configuration (load_env + app.php REQUIRED for APP_PEPPER)
require_once 'config/load_env.php';
require_once 'config/app.php';
require_once 'config/database.php';

try {
    // Get database connection
    $db = Database::getInstance()->getConnection();
    
    // Check if the admin user exists
    $stmt = $db->prepare("SELECT id, email, password_hash FROM users WHERE email = 'admin@stamgast.nl' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "User 'admin@stamgast.nl' found in database\n";
        
        // Update the password to the correct one from .env
        $password = 'Admin123!'; // Default password from .env
        $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $passwordHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);
        
        if ($passwordHash === false) {
            echo "Failed to hash password\n";
            exit(1);
        }
        
        // Update the password for the user
        $updateStmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE email = 'admin@stamgast.nl'");
        $result = $updateStmt->execute([':password_hash' => $passwordHash]);
        
        if ($result) {
            echo "Password reset successfully for admin@stamgast.nl\n";
        } else {
            echo "Failed to reset password\n";
        }
    } else {
        echo "User admin@stamgast.nl not found\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}