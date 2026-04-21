<?php
/**
 * Simple Database Setup
 * Run: http://stamgast.test/db.php
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";

try {
    // Connect without database
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "1. CONNECTED to MySQL\n";
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "2. DATABASE created: $dbname\n";
    
    // Use database
    $pdo->exec("USE $dbname");
    echo "3. USING: $dbname\n";
    
    // Create tables
    $sql = "
    CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        brand_color VARCHAR(7) DEFAULT '#FFC107',
        secret_key VARCHAR(64) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('superadmin','admin','bartender','guest') NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        birthdate DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS wallets (
        user_id INT PRIMARY KEY,
        tenant_id INT NOT NULL,
        balance_cents BIGINT DEFAULT 0,
        points_cents BIGINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS loyalty_tiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        min_deposit_cents BIGINT DEFAULT 0,
        alcohol_discount_perc DECIMAL(5,2) DEFAULT 0,
        food_discount_perc DECIMAL(5,2) DEFAULT 0,
        points_multiplier DECIMAL(3,2) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        bartender_id INT,
        type ENUM('payment','deposit','bonus','correction') NOT NULL,
        amount_alc_cents INT DEFAULT 0,
        amount_food_cents INT DEFAULT 0,
        discount_alc_cents INT DEFAULT 0,
        discount_food_cents INT DEFAULT 0,
        final_total_cents INT NOT NULL,
        points_earned INT DEFAULT 0,
        ip_address VARCHAR(45)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50),
        entity_id INT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        metadata JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    "; 
    
    $pdo->exec($sql);
    echo "4. TABLES created (6)\n";
    echo "5. Schema fixed with all columns\n";
    
    // Insert test data (skip if exists)
    $secret = bin2hex(random_bytes(32));
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    
    // Check if data exists
    $check = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO tenants (id, uuid, name, slug, secret_key) VALUES (1, 'tenant-001', 'Test Establishment', 'test', '$secret')");
    } else {
        echo "4b. tenants already exists - skipping\n";
    }
    // Check if users exist
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $pdo->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, first_name, last_name, birthdate) VALUES (1, 1, 'admin@stamgast.nl', '$hash', 'superadmin', 'Admin', 'User', NULL)");
        $pdo->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, first_name, last_name, birthdate) VALUES (2, 1, 'manager@test.nl', '$hash', 'admin', 'Manager', 'Test', '1980-01-01')");
        $pdo->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, first_name, last_name, birthdate) VALUES (3, 1, 'bartender@test.nl', '$hash', 'bartender', 'Bart', 'Tender', '1995-06-15')");
        $pdo->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, first_name, last_name, birthdate) VALUES (4, 1, 'guest@test.nl', '$hash', 'guest', 'Guest', 'User', '2000-01-01')");
    } else {
        echo "5b. users already exist - skipping\n";
    }
    
    // Check if wallets exist
    $walletCount = $pdo->query("SELECT COUNT(*) FROM wallets")->fetchColumn();
    if ($walletCount == 0) {
        $pdo->exec("INSERT INTO wallets (user_id, tenant_id, balance_cents, points_cents) VALUES (1, 1, 10000, 10000)");
        $pdo->exec("INSERT INTO wallets (user_id, tenant_id, balance_cents, points_cents) VALUES (2, 1, 5000, 5000)");
        $pdo->exec("INSERT INTO wallets (user_id, tenant_id, balance_cents, points_cents) VALUES (3, 1, 0, 0)");
        $pdo->exec("INSERT INTO wallets (user_id, tenant_id, balance_cents, points_cents) VALUES (4, 1, 0, 0)");
    } else {
        echo "5c. wallets already exist - skipping\n";
    }
    
    // Check if tiers exist
    $tierCount = $pdo->query("SELECT COUNT(*) FROM loyalty_tiers")->fetchColumn();
    if ($tierCount == 0) {
        $pdo->exec("INSERT INTO loyalty_tiers VALUES (1, 1, 'Bronze', 0, 0, 0, 1), (2, 1, 'Silver', 5000, 5, 10, 1.25), (3, 1, 'Gold', 15000, 10, 15, 1.5), (4, 1, 'Platinum', 50000, 15, 20, 2)");
    } else {
        echo "5d. tiers already exist - skipping\n";
    }
    
    echo "5. TEST DATA inserted\n";
    echo "\n✅ DONE! Database ready.\n";
    echo "Login: admin@stamgast.nl / admin123\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";