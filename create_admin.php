<?php
// Load configuration (load_env + app.php REQUIRED for APP_PEPPER)
require_once 'config/load_env.php';
require_once 'config/app.php';
require_once 'config/database.php';
require_once 'services/AuthService.php';

try {
    // Get database connection
    $db = Database::getInstance()->getConnection();
    
    // Check if superadmin user exists
    $stmt = $db->prepare("SELECT id, email, role FROM users WHERE email = 'admin@stamgast.nl' AND role = 'superadmin'");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "Superadmin user already exists with email: " . $user['email'] . "\n";
    } else {
        echo "Superadmin user does not exist. Creating one...\n";
        
        // Create superadmin user
        $authService = new AuthService($db);
        $password = 'Admin123!'; // Default password from .env
        
        // Hash password with Argon2id + pepper
        $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $passwordHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);
        
        if ($passwordHash === false) {
            echo "Failed to hash password\n";
            exit(1);
        }
        
        // Create the user
        $stmt = $db->prepare(
            "INSERT INTO users (email, password_hash, role, first_name, last_name, tenant_id) 
             VALUES (:email, :password_hash, :role, :first_name, :last_name, :tenant_id)"
        );
        
        $stmt->execute([
            ':email' => 'admin@stamgast.nl',
            ':password_hash' => $passwordHash,
            ':role' => 'superadmin',
            ':first_name' => 'Admin',
            ':last_name' => 'REGULR.vip',
            ':tenant_id' => null
        ]);
        
        $userId = $db->lastInsertId();
        echo "Created superadmin user with ID: $userId\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}