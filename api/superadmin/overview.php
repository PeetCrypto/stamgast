<?php
declare(strict_types=1);

/**
 * Super-Admin: Platform Overview Endpoint
 * GET /api/superadmin/overview - Platform-wide statistics
 */

require_once __DIR__ . '/../../models/Tenant.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Platform-wide statistics
$stats = $tenantModel->getPlatformStats();

// Additional breakdowns
$tenantList = $tenantModel->getAll();
$tenantStats = [];

foreach ($tenantList as $tenant) {
    $stmt = $db->prepare(
        'SELECT 
            COUNT(DISTINCT u.id) as user_count,
            COALESCE(SUM(CASE WHEN t.type = "deposit" THEN t.final_total_cents ELSE 0 END), 0) as total_deposits,
            COALESCE(SUM(CASE WHEN t.type = "payment" THEN t.final_total_cents ELSE 0 END), 0) as total_revenue
         FROM users u
         LEFT JOIN transactions t ON t.user_id = u.id AND t.tenant_id = :tid
         WHERE u.tenant_id = :tid'
    );
    $stmt->execute([':tid' => $tenant['id']]);
    $row = $stmt->fetch();

    $tenantStats[] = [
        'id'              => $tenant['id'],
        'name'            => $tenant['name'],
        'slug'            => $tenant['slug'],
        'mollie_status'   => $tenant['mollie_status'],
        'user_count'      => (int) ($row['user_count'] ?? 0),
        'total_deposits'  => (int) ($row['total_deposits'] ?? 0),
        'total_revenue'   => (int) ($row['total_revenue'] ?? 0),
        'created_at'      => $tenant['created_at'],
    ];
}

Response::success([
    'platform' => $stats,
    'tenants'  => $tenantStats,
]);
