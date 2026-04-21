<?php
/**
 * Fix audit_log table - add missing columns
 * Run: http://stamgast.test/fix_audit.php
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
    $stmt = $pdo->query("DESCRIBE audit_log");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Current columns: " . implode(', ', $columns) . "\n";
    
    // Add missing columns
    $missing = [
        'entity_type' => 'ADD COLUMN entity_type VARCHAR(50) NULL',
        'entity_id' => 'ADD COLUMN entity_id INT NULL',
        'user_agent' => 'ADD COLUMN user_agent TEXT NULL',
        'metadata' => 'ADD COLUMN metadata JSON NULL'
    ];
    
    foreach ($missing as $col => $sql) {
        if (!in_array($col, $columns)) {
            $pdo->exec("ALTER TABLE audit_log $sql");
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