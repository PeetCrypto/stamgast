<?php
declare(strict_types=1);

/**
 * Debug script to check actual templates in database
 * Run: http://stamgast.test/check_templates.php
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    $stmt = $pdo->query("SELECT id, type, subject, language_code, is_default, created_at, updated_at FROM email_templates ORDER BY id");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<pre style='background:#222;color:#fff;padding:20px;font-family:monospace;'>";
    echo "============================================\n";
    echo "ACTUAL TEMPLATES IN DATABASE\n";
    echo "============================================\n\n";
    
    if (empty($templates)) {
        echo "No templates found in database.\n";
    } else {
        foreach ($templates as $template) {
            echo "ID: " . $template['id'] . "\n";
            echo "Type: " . $template['type'] . "\n";
            echo "Subject: " . $template['subject'] . "\n";
            echo "Language: " . $template['language_code'] . "\n";
            echo "Default: " . ($template['is_default'] ? 'Yes' : 'No') . "\n";
            echo "Created: " . $template['created_at'] . "\n";
            echo "Updated: " . $template['updated_at'] . "\n";
            echo "----------------------------------------\n";
        }
    }
    
    echo "\n============================================\n";
    echo "END OF REPORT\n";
    echo "============================================\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";

echo "<p><a href='http://stamgast.test/superadmin/email-templates'>Terug naar email templates</a></p>";

// Delete this file after use
// unlink(__FILE__); // Uncomment to auto-delete
?>