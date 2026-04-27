<?php
declare(strict_types=1);

/**
 * Email Template Management API
 * GET    /api/email/templates              — List templates for tenant
 * GET    /api/email/templates?id=X         — Get single template
 * POST   /api/email/templates              — Create template
 * PUT    /api/email/templates              — Update template
 * DELETE /api/email/templates              — Delete template
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../models/EmailTemplate.php';

header('Content-Type: application/json');

// Auth check — rely on session like other API endpoints
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userRole = $_SESSION['role'] ?? '';
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;

$db = Database::getInstance()->getConnection();
$emailTemplate = new EmailTemplate($db);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($emailTemplate, $tenantId, $userRole);
        break;
    case 'POST':
        handlePost($emailTemplate, $userRole, $tenantId);
        break;
    case 'PUT':
        handlePut($emailTemplate, $userRole, $tenantId);
        break;
    case 'DELETE':
        handleDelete($emailTemplate);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function handleGet(EmailTemplate $model, ?int $tenantId, string $userRole): void
{
    $templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($templateId > 0) {
        // Get single template by ID
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tpl) {
            echo json_encode($tpl);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
        }
        return;
    }

    // Superadmins see all global templates (tenant_id IS NULL); admins see their tenant's templates
    if ($userRole === 'superadmin') {
        $templates = $model->getTemplatesByTenant(null);
    } else {
        $templates = $model->getTemplatesByTenant($tenantId);
    }

    echo json_encode($templates);
}

function handlePost(EmailTemplate $model, string $userRole, ?int $tenantId): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    foreach (['type', 'subject', 'content'] as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    if (!$model->canManageTemplate($input['type'], $userRole)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: cannot manage this template type']);
        return;
    }

    // Auto-set tenant_id and is_default based on role
    if ($userRole === 'superadmin') {
        $input['tenant_id']  = null;
        $input['is_default'] = 1;
    } else {
        $input['tenant_id']  = $input['tenant_id'] ?? $tenantId;
        $input['is_default'] = $input['is_default'] ?? 0;
    }

    if ($model->saveTemplate($input)) {
        http_response_code(201);
        echo json_encode(['message' => 'Template saved successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save template']);
    }
}

function handlePut(EmailTemplate $model, string $userRole, ?int $tenantId): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    foreach (['id', 'type', 'subject', 'content'] as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    if (!$model->canManageTemplate($input['type'], $userRole)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: cannot manage this template type']);
        return;
    }

    // Auto-set tenant_id and is_default based on role
    if ($userRole === 'superadmin') {
        $input['tenant_id']  = null;
        $input['is_default'] = 1;
    } else {
        $input['tenant_id']  = $input['tenant_id'] ?? $tenantId;
        $input['is_default'] = $input['is_default'] ?? 0;
    }

    if ($model->saveTemplate($input)) {
        echo json_encode(['message' => 'Template updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update template']);
    }
}

function handleDelete(EmailTemplate $model): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Template ID is required']);
        return;
    }

    if ($model->deleteTemplate((int)$input['id'])) {
        echo json_encode(['message' => 'Template deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete template']);
    }
}
