<?php
declare(strict_types=1);

/**
 * GET /api/wallet/packages
 * Returns active loyalty packages for the guest's tenant.
 * Used by the guest wallet to show available top-up packages with discounts.
 *
 * Auth: guest+ (any authenticated user)
 */

require_once __DIR__ . '/../../models/LoyaltyTier.php';

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

$db = Database::getInstance()->getConnection();
$tierModel = new LoyaltyTier($db);

// Only return active packages
$tiers = $tierModel->getActiveByTenant($tenantId);

$packages = array_map(function ($tier) {
    return [
        'id'                    => (int) $tier['id'],
        'name'                  => $tier['name'],
        'topup_amount_cents'    => (int) $tier['topup_amount_cents'],
        'model_type'            => $tier['model_type'] ?? 'discount',
        'bonus_percentage'      => (float) ($tier['bonus_percentage'] ?? 0),
        'bonus_cents'           => (int) ($tier['bonus_cents'] ?? 0),
        'alcohol_discount_perc' => (float) $tier['alcohol_discount_perc'],
        'food_discount_perc'    => (float) $tier['food_discount_perc'],
        'points_multiplier'     => (float) $tier['points_multiplier'],
    ];
}, $tiers);

Response::success([
    'packages' => $packages,
]);
