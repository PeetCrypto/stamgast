<?php
declare(strict_types=1);

/**
 * GET /api/wallet/history
 * Get paginated transaction history for the authenticated user.
 *
 * Auth: guest+ (any authenticated user)
 *
 * Query params: ?page=1&limit=20
 * Response: { transactions: [...], total: int, page: int, limit: int }
 */

require_once __DIR__ . '/../../services/WalletService.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(max(1, (int) ($_GET['limit'] ?? DEFAULT_PAGE_SIZE)), MAX_PAGE_SIZE);

try {
    $db = Database::getInstance()->getConnection();
    $walletService = new WalletService($db);

    $result = $walletService->getHistory($userId, $tenantId, $page, $limit);

    // Format amounts for display
    $formatted = array_map(function (array $tx): array {
        $tx['amount_alc_display']    = centsToEuro((int) $tx['amount_alc_cents']);
        $tx['amount_food_display']   = centsToEuro((int) $tx['amount_food_cents']);
        $tx['discount_alc_display']  = centsToEuro((int) $tx['discount_alc_cents']);
        $tx['discount_food_display'] = centsToEuro((int) $tx['discount_food_cents']);
        $tx['final_total_display']   = centsToEuro((int) $tx['final_total_cents']);
        $tx['points_earned_display'] = centsToEuro((int) $tx['points_earned']);
        return $tx;
    }, $result['transactions']);

    $result['transactions'] = $formatted;

    Response::success($result);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Geschiedenis ophalen mislukt: ' . $e->getMessage());
    }
    Response::internalError('Geschiedenis ophalen mislukt');
}
