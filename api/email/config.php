<?php
declare(strict_types=1);

/**
 * Email Configuration API (Superadmin only)
 * GET    /api/email/config  — Get current config (credentials masked)
 * POST   /api/email/config  — Save config
 * DELETE /api/email/config  — Delete config
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../models/EmailConfig.php';

header('Content-Type: application/json');

// Auth check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userRole = $_SESSION['role'] ?? '';
if ($userRole !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: only superadmins can manage email configuration']);
    exit;
}

$db = Database::getInstance()->getConnection();
$emailConfig = new EmailConfig($db);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $config = $emailConfig->getActiveConfig();
        if ($config) {
            // Never expose credentials to the client
            unset($config['smtp_user'], $config['smtp_pass']);
            echo json_encode($config);
        } else {
            echo json_encode(null);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON input']);
            break;
        }

        foreach (['provider', 'smtp_host', 'smtp_port', 'smtp_user'] as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                break 2;
            }
        }

        // Set defaults for optional fields
        $input['smtp_encryption'] = $input['smtp_encryption'] ?? 'tls';
        $input['smtp_pass'] = $input['smtp_pass'] ?? '';
        $input['from_email'] = $input['from_email'] ?? 'no-reply@regulr.vip';
        $input['from_name'] = $input['from_name'] ?? 'STAMGAST';
        $input['is_active'] = $input['is_active'] ?? 1;

        if ($emailConfig->saveConfig($input)) {
            echo json_encode(['message' => 'Configuration saved successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save configuration']);
        }
        break;

    case 'DELETE':
        $active = $emailConfig->getActiveConfig();
        if ($active && $emailConfig->deleteConfig($active['id'])) {
            echo json_encode(['message' => 'Configuration deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete configuration']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
