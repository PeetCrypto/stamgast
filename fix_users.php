<?php
/**
 * Fix users table - add missing columns
 * Run: http://stamgast.test/fix_users.php
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to MySQL\n";
    
    // Check what columns exist
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Current columns: " . implode(', ', $columns) . "\n";
    
    // Add missing columns
    $missing = [
        'last_activity' => 'ADD COLUMN last_activity TIMESTAMP NULL',
        'photo_url' => 'ADD COLUMN photo_url VARCHAR(255) NULL',
        'photo_status' => "ADD COLUMN photo_status ENUM('unvalidated','validated','blocked') DEFAULT 'unvalidated'",
        'push_token' => 'ADD COLUMN push_token TEXT NULL',
        'updated_at' => 'ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    foreach ($missing as $col => $sql) {
        if (!in_array($col, $columns)) {
            $pdo->exec("ALTER TABLE users $sql");
            echo "Added: $col\n";
        } else {
            echo "Already exists: $col\n";
        }
    }
    
    echo "\n✅ DONE! Now test login:\n";
    echo "http://stamgast.test/login\n";
    echo "Email: admin@stamgast.nl\n";
    echo "Password: admin123\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";