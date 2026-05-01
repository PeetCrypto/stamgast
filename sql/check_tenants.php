<?php
// Database configuration
$host = 'localhost';
$dbname = 'stamgast_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query('SELECT id, name, slug FROM tenants WHERE slug IS NULL OR slug = ""');
    $tenants = $stmt->fetchAll();
    
    if (count($tenants) > 0) {
        echo "Tenants met lege slugs gevonden:\n";
        foreach($tenants as $tenant) {
            echo "ID: " . $tenant['id'] . ", Naam: " . $tenant['name'] . ", Slug: " . $tenant['slug'] . "\n";
        }
    } else {
        echo "Geen tenants met lege slugs gevonden\n";
    }
} catch (Exception $e) {
    echo "Fout bij databaseverbinding: " . $e->getMessage() . "\n";
}
?>