<?php
declare(strict_types=1);

/**
 * Admin Tiers/Package API
 * GET  /api/admin/tiers
 * POST /api/admin/tiers  { action: 'create'|'update'|'delete'|'toggle', ... }
 */

require_once __DIR__ . '/../../models/LoyaltyTier.php';

$db = Database::getInstance()->getConnection();
$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

$tierModel = new LoyaltyTier($db);
$method    = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- LIST TIERS ---
    $tiers = $tierModel->getByTenant($tenantId);

    $result = array_map(function ($tier) {
        return [
            'id'                    => (int) $tier['id'],
            'name'                  => $tier['name'],
            'min_deposit_cents'     => (int) $tier['min_deposit_cents'],
            'topup_amount_cents'    => (int) $tier['topup_amount_cents'],
            'alcohol_discount_perc' => (float) $tier['alcohol_discount_perc'],
            'food_discount_perc'    => (float) $tier['food_discount_perc'],
            'points_multiplier'     => (float) $tier['points_multiplier'],
            'is_active'             => (int) $tier['is_active'],
            'sort_order'            => (int) $tier['sort_order'],
        ];
    }, $tiers);

    Response::success([
        'tiers' => $result,
    ]);

} elseif ($method === 'POST') {
    // --- CREATE / UPDATE / DELETE / TOGGLE TIER ---
    $input  = getJsonInput();

    // Determine action
    $action = $input['action'] ?? '';
    if (empty($action)) {
        if (!empty($input['tier_id'])) {
            $action = 'update';
        } else {
            $action = 'create';
        }
    }

    if ($action === 'create') {
        $name               = trim($input['name'] ?? '');
        $minDepositCents    = (int) ($input['min_deposit_cents'] ?? 0);
        $topupAmountCents   = (int) ($input['topup_amount_cents'] ?? LoyaltyTier::MIN_TOPUP_CENTS);
        $alcDiscount        = (float) ($input['alcohol_discount_perc'] ?? 0);
        $foodDiscount       = (float) ($input['food_discount_perc'] ?? 0);
        $pointsMultiplier   = (float) ($input['points_multiplier'] ?? 1.0);
        $sortOrder          = (int) ($input['sort_order'] ?? $tierModel->getNextSortOrder($tenantId));
        $isActive           = isset($input['is_active']) ? (int) $input['is_active'] : 1;

        // Validate
        if ($name === '') {
            Response::error('Naam is verplicht', 'VALIDATION_ERROR', 422);
        }
        if ($topupAmountCents < LoyaltyTier::MIN_TOPUP_CENTS) {
            Response::error('Opwaardeerbedrag moet minimaal €' . (LoyaltyTier::MIN_TOPUP_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
        }
        if ($topupAmountCents > DEPOSIT_MAX_CENTS) {
            Response::error('Opwaardeerbedrag mag maximaal €' . (DEPOSIT_MAX_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
        }
        if ($alcDiscount < 0 || $alcDiscount > ALCOHOL_DISCOUNT_MAX) {
            Response::error('Alcohol korting moet tussen 0% en ' . ALCOHOL_DISCOUNT_MAX . '% zijn (wettelijk maximum)', 'VALIDATION_ERROR', 422);
        }
        if ($foodDiscount < 0 || $foodDiscount > FOOD_DISCOUNT_MAX) {
            Response::error('Eten korting moet tussen 0% en ' . FOOD_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
        }
        if ($pointsMultiplier < 0.5 || $pointsMultiplier > 10) {
            Response::error('Punten multiplier moet tussen 0.5 en 10 zijn', 'VALIDATION_ERROR', 422);
        }

        $tierId = $tierModel->create([
            'tenant_id'             => $tenantId,
            'name'                  => $name,
            'min_deposit_cents'     => $minDepositCents,
            'topup_amount_cents'    => $topupAmountCents,
            'alcohol_discount_perc' => $alcDiscount,
            'food_discount_perc'    => $foodDiscount,
            'points_multiplier'     => $pointsMultiplier,
            'is_active'             => $isActive,
            'sort_order'            => $sortOrder,
        ]);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.created', 'tier', $tierId, [
            'name' => $name,
            'topup_amount_cents' => $topupAmountCents,
        ]);

        Response::success([
            'message' => 'Pakket aangemaakt',
            'tier_id' => $tierId,
        ], 201);

    } elseif ($action === 'update') {
        $tierId = (int) ($input['tier_id'] ?? 0);
        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        // Verify tier belongs to this tenant
        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Pakket niet gevonden', 'NOT_FOUND', 404);
        }

        // Build update data with validation
        $data = [];
        if (isset($input['name'])) {
            $data['name'] = trim($input['name']);
            if ($data['name'] === '') {
                Response::error('Naam mag niet leeg zijn', 'VALIDATION_ERROR', 422);
            }
        }
        if (isset($input['min_deposit_cents'])) {
            $data['min_deposit_cents'] = (int) $input['min_deposit_cents'];
        }
        if (isset($input['topup_amount_cents'])) {
            $val = (int) $input['topup_amount_cents'];
            if ($val < LoyaltyTier::MIN_TOPUP_CENTS) {
                Response::error('Opwaardeerbedrag moet minimaal €' . (LoyaltyTier::MIN_TOPUP_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
            }
            if ($val > DEPOSIT_MAX_CENTS) {
                Response::error('Opwaardeerbedrag mag maximaal €' . (DEPOSIT_MAX_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
            }
            $data['topup_amount_cents'] = $val;
        }
        if (isset($input['alcohol_discount_perc'])) {
            $val = (float) $input['alcohol_discount_perc'];
            if ($val < 0 || $val > ALCOHOL_DISCOUNT_MAX) {
                Response::error('Alcohol korting moet tussen 0% en ' . ALCOHOL_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
            $data['alcohol_discount_perc'] = $val;
        }
        if (isset($input['food_discount_perc'])) {
            $val = (float) $input['food_discount_perc'];
            if ($val < 0 || $val > FOOD_DISCOUNT_MAX) {
                Response::error('Eten korting moet tussen 0% en ' . FOOD_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
            $data['food_discount_perc'] = $val;
        }
        if (isset($input['points_multiplier'])) {
            $val = (float) $input['points_multiplier'];
            if ($val < 0.5 || $val > 10) {
                Response::error('Punten multiplier moet tussen 0.5 en 10 zijn', 'VALIDATION_ERROR', 422);
            }
            $data['points_multiplier'] = $val;
        }
        if (isset($input['is_active'])) {
            $data['is_active'] = (int) $input['is_active'];
        }
        if (isset($input['sort_order'])) {
            $data['sort_order'] = (int) $input['sort_order'];
        }

        if (empty($data)) {
            Response::error('Geen velden om te updaten', 'NO_DATA', 400);
        }

        $tierModel->update($tierId, $tenantId, $data);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.updated', 'tier', $tierId, $data);

        Response::success([
            'message' => 'Pakket bijgewerkt',
            'tier_id' => $tierId,
        ]);

    } elseif ($action === 'toggle') {
        // Toggle tier active/inactive
        $tierId = (int) ($input['tier_id'] ?? 0);
        $active = (bool) ($input['is_active'] ?? false);

        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Pakket niet gevonden', 'NOT_FOUND', 404);
        }

        $tierModel->toggle($tierId, $tenantId, $active);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.toggled', 'tier', $tierId, [
            'is_active' => $active ? 1 : 0,
            'name' => $tier['name'],
        ]);

        Response::success([
            'message' => $active ? 'Pakket ingeschakeld' : 'Pakket uitgeschakeld',
            'tier_id' => $tierId,
            'is_active' => $active ? 1 : 0,
        ]);

    } elseif ($action === 'delete') {
        $tierId = (int) ($input['tier_id'] ?? 0);
        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Pakket niet gevonden', 'NOT_FOUND', 404);
        }

        $tierModel->delete($tierId, $tenantId);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.deleted', 'tier', $tierId, [
            'name' => $tier['name'],
        ]);

        Response::success([
            'message' => 'Pakket verwijderd',
            'tier_id' => $tierId,
        ]);

    } else {
        Response::error('Ongeldige actie. Gebruik: create, update, delete, toggle', 'INVALID_ACTION', 400);
    }

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
