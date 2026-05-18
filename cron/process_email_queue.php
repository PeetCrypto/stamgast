<?php
/**
 * REGULR.vip - Cron: Process Email Queue
 *
 * Verwerkt pending marketing e-mails uit de wachtrij.
 * Roept MarketingService::processQueue() aan voor alle tenants.
 *
 * Local test:  php cron/process_email_queue.php
 * Hostinger:   php /home/xxx/domains/app.regulr.vip/public_html/cron/process_email_queue.php
 *              Elke 5 minuten aanraden.
 */

// --- Bootstrap (zonder session/auth — CLI context) ---
require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/audit.php';

// Autoload models (Tenant, LoyaltyTier, EmailConfig, EmailTemplate, etc.)
spl_autoload_register(function (string $class) {
    $modelPath = __DIR__ . '/../models/' . $class . '.php';
    if (file_exists($modelPath)) {
        require_once $modelPath;
    }
});

// Load services
require_once __DIR__ . '/../services/Email/EmailService.php';
require_once __DIR__ . '/../services/MarketingService.php';

// --- Process Queue ---
try {
    $db        = Database::getInstance()->getConnection();
    $marketing = new MarketingService($db);

    // tenantId=0 → alle tenants, batchSize=50 per run
    $result = $marketing->processQueue(0, 50);

    // Output (CLI-friendly plain text, maar ook JSON-parseerbaar)
    $timestamp = date('c');
    $line = "[{$timestamp}] Queue processed: {$result['sent']} sent, {$result['failed']} failed ({$result['processed']} total)";

    // CLI output
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'timestamp' => $timestamp,
            'processed' => $result['processed'],
            'sent'      => $result['sent'],
            'failed'    => $result['failed'],
        ]);
    }
} catch (\Throwable $e) {
    $errorMsg = '[' . date('c') . '] CRON ERROR: ' . $e->getMessage();
    error_log($errorMsg);

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMsg . PHP_EOL);
        exit(1);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}
