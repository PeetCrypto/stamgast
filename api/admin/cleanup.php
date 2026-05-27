<?php
declare(strict_types=1);

/**
 * POST /api/admin/cleanup
 * Cleanup old data: audit_log (push.*), email_queue (sent/failed), notifications (system)
 *
 * Retention: 30 days default, configurable via ?days=N
 * Keeps:
 *   - notifications with type IN ('deposit','payment','bonus','correction') — FOREVER
 *   - email_queue with status = 'pending' — regardless of age
 * Deletes:
 *   - audit_log where action LIKE 'push.%' AND older than 30 days
 *   - email_queue where status IN ('sent','failed') AND older than 30 days
 *   - notifications where type = 'system' AND older than 30 days
 */

$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

$db = Database::getInstance()->getConnection();

$days = min(max(1, (int) ($_POST['days'] ?? 30)), 365);

$results = [];

try {
    $db->beginTransaction();

    // 1. Cleanup audit_log: push.* entries older than N days
    $stmt = $db->prepare(
        "DELETE FROM `audit_log`
         WHERE `tenant_id` = :tid
           AND `action` LIKE 'push.%'
           AND `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)"
    );
    $stmt->execute([':tid' => $tenantId, ':days' => $days]);
    $results['audit_log_push'] = $stmt->rowCount();

    // 2. Cleanup email_queue: sent/failed entries older than N days
    $stmt = $db->prepare(
        "DELETE FROM `email_queue`
         WHERE `tenant_id` = :tid
           AND `status` IN ('sent', 'failed')
           AND `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)"
    );
    $stmt->execute([':tid' => $tenantId, ':days' => $days]);
    $results['email_queue_sent_failed'] = $stmt->rowCount();

    // 3. Cleanup notifications: system type older than N days
    $stmt = $db->prepare(
        "DELETE FROM `notifications`
         WHERE `tenant_id` = :tid
           AND `type` = 'system'
           AND `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)"
    );
    $stmt->execute([':tid' => $tenantId, ':days' => $days]);
    $results['notifications_system'] = $stmt->rowCount();

    // 4. Cleanup soft-deleted notifications older than N days (already deleted by user)
    $stmt = $db->prepare(
        "DELETE FROM `notifications`
         WHERE `tenant_id` = :tid
           AND `deleted_at` IS NOT NULL
           AND `deleted_at` < DATE_SUB(NOW(), INTERVAL :days DAY)"
    );
    $stmt->execute([':tid' => $tenantId, ':days' => $days]);
    $results['notifications_soft_deleted'] = $stmt->rowCount();

    $db->commit();

    // Audit the cleanup action
    (new Audit($db))->log(
        $tenantId,
        currentUserId(),
        'admin.cleanup',
        'system',
        null,
        ['days' => $days, 'results' => $results]
    );

    $totalDeleted = array_sum($results);

    Response::success([
        'message' => 'Opruiming voltooid',
        'days'    => $days,
        'deleted' => $results,
        'total'   => $totalDeleted,
    ]);

} catch (\Throwable $e) {
    $db->rollBack();
    error_log('[Cleanup] Error: ' . $e->getMessage());
    Response::internalError('Opruimen mislukt');
}
