<?php
declare(strict_types=1);

/**
 * Super-Admin: Invoice Management Endpoints
 *
 * GET                           - List all invoices (paginated, filterable by status)
 * GET ?id=X                     - Invoice detail with linked fees
 * POST action=generate          - Generate invoice for one tenant + period
 * POST action=generate_all      - Batch generate for all active tenants
 * POST action=update_status     - Change invoice status (draft→sent→paid→cancelled)
 * POST action=add_note          - Add internal note to invoice
 */

require_once __DIR__ . '/../../models/PlatformInvoice.php';
require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$invoiceModel = new PlatformInvoice($db);
$feeModel = new PlatformFee($db);
$feeService = new PlatformFeeService($db);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($invoiceModel);
        break;

    case 'POST':
        $input = getJsonInput();
        $action = $input['action'] ?? 'list';

        switch ($action) {
            case 'generate':
                handleGenerate($feeService, $input);
                break;

            case 'generate_all':
                handleGenerateAll($feeService, $input);
                break;

            case 'update_status':
                handleUpdateStatus($invoiceModel, $input, $db);
                break;

            case 'add_note':
                handleAddNote($invoiceModel, $input, $db);
                break;

            default:
                Response::error('Invalid action. Use: generate, generate_all, update_status, add_note', 'INVALID_ACTION', 400);
        }
        break;

    default:
        Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// ── GET handlers ────────────────────────────────────────────────────────────

/**
 * GET: list invoices or get single invoice detail
 */
function handleGet(PlatformInvoice $invoiceModel): void
{
    $invoiceId = (int) ($_GET['id'] ?? 0);

    if ($invoiceId > 0) {
        // Single invoice detail with linked fees
        $invoice = $invoiceModel->findById($invoiceId);
        if (!$invoice) {
            Response::notFound('Factuur niet gevonden');
        }

        $fees = $invoiceModel->getFees($invoiceId);

        Response::success([
            'invoice' => $invoice,
            'fees'    => $fees,
        ]);
    } else {
        // List all invoices
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 50);
        $status = $_GET['status'] ?? null;

        $result = $invoiceModel->getAll($page, $limit, $status);

        // Also include totals
        $totals = $invoiceModel->getTotals();

        Response::success([
            'invoices' => $result['invoices'],
            'totals'   => $totals,
            'total'    => $result['total'],
            'page'     => $result['page'],
            'limit'    => $result['limit'],
        ]);
    }
}

// ── POST handlers ───────────────────────────────────────────────────────────

/**
 * POST action=generate: generate invoice for one tenant
 * Required: tenant_id, period_start, period_end
 * Optional: period_type (defaults to tenant's invoice_period)
 */
function handleGenerate(PlatformFeeService $feeService, array $input): void
{
    $tenantId = (int) ($input['tenant_id'] ?? 0);
    $periodStart = $input['period_start'] ?? '';
    $periodEnd = $input['period_end'] ?? '';

    if ($tenantId <= 0) {
        Response::error('tenant_id is required', 'MISSING_FIELD', 400);
    }
    if (empty($periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
        Response::error('Valid period_start (YYYY-MM-DD) is required', 'INVALID_PERIOD_START', 400);
    }
    if (empty($periodEnd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
        Response::error('Valid period_end (YYYY-MM-DD) is required', 'INVALID_PERIOD_END', 400);
    }
    if ($periodStart > $periodEnd) {
        Response::error('period_start must be before period_end', 'INVALID_PERIOD_RANGE', 400);
    }

    try {
        $result = $feeService->generateMonthlyInvoice(
            $tenantId,
            $periodStart,
            $periodEnd,
            $input['period_type'] ?? null
        );

        Response::success($result, 201);
    } catch (\RuntimeException $e) {
        Response::error($e->getMessage(), 'INVOICE_GENERATION_FAILED', 400);
    }
}

/**
 * POST action=generate_all: batch generate for all active tenants
 * Required: period_start, period_end
 */
function handleGenerateAll(PlatformFeeService $feeService, array $input): void
{
    $periodStart = $input['period_start'] ?? '';
    $periodEnd = $input['period_end'] ?? '';

    if (empty($periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
        Response::error('Valid period_start (YYYY-MM-DD) is required', 'INVALID_PERIOD_START', 400);
    }
    if (empty($periodEnd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
        Response::error('Valid period_end (YYYY-MM-DD) is required', 'INVALID_PERIOD_END', 400);
    }

    $results = $feeService->generateAllInvoices($periodStart, $periodEnd);

    Response::success([
        'generated' => count($results),
        'invoices'  => $results,
    ]);
}

/**
 * POST action=update_status: change invoice status
 * Required: invoice_id, status
 */
function handleUpdateStatus(PlatformInvoice $invoiceModel, array $input, PDO $db): void
{
    $invoiceId = (int) ($input['invoice_id'] ?? 0);
    $newStatus = $input['status'] ?? '';

    if ($invoiceId <= 0) {
        Response::error('invoice_id is required', 'MISSING_FIELD', 400);
    }
    if (empty($newStatus)) {
        Response::error('status is required', 'MISSING_FIELD', 400);
    }

    $invoice = $invoiceModel->findById($invoiceId);
    if (!$invoice) {
        Response::notFound('Factuur niet gevonden');
    }

    // Validate status transitions
    $allowedTransitions = [
        'draft'     => ['sent', 'cancelled'],
        'sent'      => ['paid', 'overdue', 'cancelled'],
        'overdue'   => ['paid', 'cancelled'],
        'paid'      => [],
        'cancelled' => ['draft'],
    ];

    $currentStatus = $invoice['status'];
    if (!isset($allowedTransitions[$currentStatus])) {
        Response::error("Unknown current status: {$currentStatus}", 'INVALID_STATUS', 400);
    }
    if (!in_array($newStatus, $allowedTransitions[$currentStatus], true)) {
        Response::error(
            "Invalid transition: {$currentStatus} → {$newStatus}. Allowed: " .
            implode(', ', $allowedTransitions[$currentStatus] ?: ['none']),
            'INVALID_TRANSITION',
            400
        );
    }

    $invoiceModel->updateStatus($invoiceId, $newStatus);

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        0,
        currentUserId(),
        'invoice.status_changed',
        'invoice',
        $invoiceId,
        [
            'invoice_number'  => $invoice['invoice_number'],
            'old_status'      => $currentStatus,
            'new_status'      => $newStatus,
            'tenant_id'       => $invoice['tenant_id'],
        ]
    );

    $updated = $invoiceModel->findById($invoiceId);

    Response::success(['invoice' => $updated]);
}

/**
 * POST action=add_note: add internal note to invoice
 * Required: invoice_id, notes
 */
function handleAddNote(PlatformInvoice $invoiceModel, array $input, PDO $db): void
{
    $invoiceId = (int) ($input['invoice_id'] ?? 0);
    $notes = trim($input['notes'] ?? '');

    if ($invoiceId <= 0) {
        Response::error('invoice_id is required', 'MISSING_FIELD', 400);
    }
    if (empty($notes)) {
        Response::error('notes cannot be empty', 'MISSING_FIELD', 400);
    }

    $invoice = $invoiceModel->findById($invoiceId);
    if (!$invoice) {
        Response::notFound('Factuur niet gevonden');
    }

    $stmt = $db->prepare('UPDATE `platform_invoices` SET `notes` = :notes WHERE `id` = :id');
    $stmt->execute([':notes' => $notes, ':id' => $invoiceId]);

    $audit = new Audit($db);
    $audit->log(
        0,
        currentUserId(),
        'invoice.note_added',
        'invoice',
        $invoiceId,
        ['invoice_number' => $invoice['invoice_number']]
    );

    Response::success(['updated' => true]);
}
