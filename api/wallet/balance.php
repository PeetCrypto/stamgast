<?php
declare(strict_types=1);

/**
 * GET /api/wallet/balance
 * Returns wallet balance + active tier info for the authenticated user
 */

require_once __DIR__ . '/../../services/WalletService.php';

if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$userId = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$db = Database::getInstance()->getConnection();
$walletService = new WalletService($db);

try {
    $balance = $walletService->getBalance($userId, $tenantId);
    Response::success($balance);
} catch (\Throwable $e) {
    Response::internalError('Saldo ophalen mislukt');
}
