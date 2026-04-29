<?php
declare(strict_types=1);

/**
 * Super-Admin: Platform Fee Overview Endpoints
 *
 * GET ?action=overview          - Platform-wide fee totals (today, month, all-time)
 * GET ?action=per_tenant        - Per-tenant fee breakdown with totals
 * GET ?action=tenant_fees       - Paginated fee list for one tenant (requires tenant_id)
 * GET ?action=tenant_summary    - Fee summary for tenant detail (requires tenant_id)
 */

require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$feeModel = new PlatformFee($db);
$feeService = new PlatformFeeService($db);

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$action = $_GET['action'] ?? 'overview';

switch ($action) {
    case 'overview':
        handleOverview($feeModel, $feeService);
        break;

    case 'per_tenant':
        handlePerTenant($feeService);
        break;

    case 'tenant_fees':
        handleTenantFees($feeModel);
        break;

    case 'tenant_summary':
        handleTenantSummary($feeService);
        break;

    default:
        Response::error('Invalid action. Use: overview, per_tenant, tenant_fees, tenant_summary', 'INVALID_ACTION', 400);
}

/**
 * Platform-wide fee totals
 */
function handleOverview(PlatformFee $feeModel, PlatformFeeService $feeService): void
{
    $platformOverview = $feeModel->getPlatformOverview();
    $platformTotals = $feeService->getPlatformTotals();

    Response::success([
        'overview' => $platformOverview,
        'totals'   => $platformTotals,
    ]);
}

/**
 * Per-tenant fee breakdown (sorted by fee total desc)
 * Optional: ?start=YYYY-MM-DD&end=YYYY-MM-DD
 */
function handlePerTenant(PlatformFeeService $feeService): void
{
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    // Validate date format if provided
    if ($start !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        Response::error('Invalid start date format. Use YYYY-MM-DD', 'INVALID_DATE', 400);
    }
    if ($end !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        Response::error('Invalid end date format. Use YYYY-MM-DD', 'INVALID_DATE', 400);
    }

    $perTenant = $feeService->getPerTenantTotals($start, $end);

    Response::success([
        'tenants' => $perTenant,
        'period'  => ['start' => $start, 'end' => $end],
    ]);
}

/**
 * Paginated fee list for a single tenant
 * Required: ?tenant_id=X
 * Optional: ?status=collected|invoiced|settled & ?page=1 & ?limit=50
 */
function handleTenantFees(PlatformFee $feeModel): void
{
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        Response::error('tenant_id is required', 'MISSING_FIELD', 400);
    }

    $status = $_GET['status'] ?? null;
    $page = (int) ($_GET['page'] ?? 1);
    $limit = (int) ($_GET['limit'] ?? 50);

    $result = $feeModel->getByTenant($tenantId, $status, $page, $limit);

    Response::success($result);
}

/**
 * Fee summary stats for a specific tenant (for tenant detail view)
 * Required: ?tenant_id=X
 */
function handleTenantSummary(PlatformFeeService $feeService): void
{
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        Response::error('tenant_id is required', 'MISSING_FIELD', 400);
    }

    $stats = $feeService->getTenantFeeStats($tenantId);

    Response::success(['stats' => $stats]);
}
