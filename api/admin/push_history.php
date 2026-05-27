<?php
declare(strict_types=1);

/**
 * Admin Push History API - Paginated
 * GET /api/admin/push_history
 *
 * Returns paginated push notification send history from audit_log.
 * Only includes actual sends (notification_sent, broadcast_sent).
 *
 * Query params:
 *   page     (int) — Page number, default 1
 *   per_page (int) — Items per page, default 10, max 50
 */

$db = Database::getInstance()->getConnection();
$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(max(1, (int) ($_GET['per_page'] ?? 10)), 50);
$offset  = ($page - 1) * $perPage;

$pushActions = "'push.notification_sent', 'push.broadcast_sent'";

// ==========================================
// Total count for pagination
// ==========================================
$countStmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM `audit_log`
     WHERE `tenant_id` = :tid
       AND `action` IN ({$pushActions})"
);
$countStmt->execute([':tid' => $tenantId]);
$totalItems  = (int) $countStmt->fetchColumn();
$totalPages  = (int) ceil($totalItems / $perPage);

// ==========================================
// Paginated items
// ==========================================
$stmt = $db->prepare(
    "SELECT `action`, `metadata`, `created_at`
     FROM `audit_log`
     WHERE `tenant_id` = :tid
       AND `action` IN ({$pushActions})
     ORDER BY `created_at` DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$items = array_map(function (array $row): array {
    return [
        'action'     => $row['action'],
        'details'    => json_decode($row['metadata'] ?? '{}', true) ?? [],
        'created_at' => $row['created_at'],
    ];
}, $rows);

// ==========================================
// Push stats (7 days) — included for stat cards
// ==========================================
$statsStmt = $db->prepare(
    "SELECT `metadata`
     FROM `audit_log`
     WHERE `tenant_id` = :tid
       AND `action` IN ({$pushActions})
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
);
$statsStmt->execute([':tid' => $tenantId]);
$statsRows = $statsStmt->fetchAll();

$sent7d  = 0;
$failed7d = 0;
foreach ($statsRows as $sRow) {
    $meta = json_decode($sRow['metadata'] ?? '{}', true) ?? [];
    $sent7d  += (int) ($meta['sent'] ?? 0);
    $failed7d += (int) ($meta['failed'] ?? 0);
}

Response::success([
    'items' => $items,
    'push_stats' => [
        'sent_7d'   => $sent7d,
        'failed_7d' => $failed7d,
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
    ],
]);
