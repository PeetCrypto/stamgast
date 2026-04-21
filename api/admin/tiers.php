<?php
declare(strict_types=1);

/**
 * Admin Tiers API
 * GET  /api/admin/tiers
 * POST /api/admin/tiers  { action: 'create'|'update'|'delete', ... }
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
            'alcohol_discount_perc' => (float) $tier['alcohol_discount_perc'],
            'food_discount_perc'    => (float) $tier['food_discount_perc'],
            'points_multiplier'     => (float) $tier['points_multiplier'],
            'created_at'            => $tier['created_at'],
        ];
    }, $tiers);

    Response::success([
        'tiers' => $result,
    ]);

} elseif ($method === 'POST') {
    // --- CREATE / UPDATE / DELETE TIER ---
    $input  = getJsonInput();
    $action = $input['action'] ?? ($input['tier_id'] ? 'update' : 'create');

    // Determine action based on presence of tier_id if action not explicitly set
    if (empty($input['action'])) {
        if (!empty($input['tier_id'])) {
            $action = 'update';
        } else {
            $action = 'create';
        }
    }

    if ($action === 'create') {
        $name               = trim($input['name'] ?? '');
        $minDepositCents    = (int) ($input['min_deposit_cents'] ?? 0);
        $alcDiscount        = (float) ($input['alcohol_discount_perc'] ?? 0);
        $foodDiscount       = (float) ($input['food_discount_perc'] ?? 0);
        $pointsMultiplier   = (float) ($input['points_multiplier'] ?? 1.0);

        // Validate
        if ($name === '') {
            Response::error('Naam is verplicht', 'VALIDATION_ERROR', 422);
        }
        if ($alcDiscount < 0 || $alcDiscount > 25) {
            Response::error('Alcohol korting moet tussen 0% en 25% zijn (wettelijk maximum)', 'VALIDATION_ERROR', 422);
        }
        if ($foodDiscount < 0 || $foodDiscount > 100) {
            Response::error('Eten korting moet tussen 0% en 100% zijn', 'VALIDATION_ERROR', 422);
        }
        if ($pointsMultiplier < 0.5 || $pointsMultiplier > 10) {
            Response::error('Punten multiplier moet tussen 0.5 en 10 zijn', 'VALIDATION_ERROR', 422);
        }

        $tierId = $tierModel->create([
            'tenant_id'             => $tenantId,
            'name'                  => $name,
            'min_deposit_cents'     => $minDepositCents,
            'alcohol_discount_perc' => $alcDiscount,
            'food_discount_perc'    => $foodDiscount,
            'points_multiplier'     => $pointsMultiplier,
        ]);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.created', 'tier', $tierId, [
            'name' => $name,
        ]);

        Response::success([
            'message' => 'Tier aangemaakt',
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
            Response::error('Tier niet gevonden', 'NOT_FOUND', 404);
        }

        // Build update data
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
        if (isset($input['alcohol_discount_perc'])) {
            $val = (float) $input['alcohol_discount_perc'];
            if ($val < 0 || $val > 25) {
                Response::error('Alcohol korting moet tussen 0% en 25% zijn', 'VALIDATION_ERROR', 422);
            }
            $data['alcohol_discount_perc'] = $val;
        }
        if (isset($input['food_discount_perc'])) {
            $val = (float) $input['food_discount_perc'];
            if ($val < 0 || $val > 100) {
                Response::error('Eten korting moet tussen 0% en 100% zijn', 'VALIDATION_ERROR', 422);
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

        if (empty($data)) {
            Response::error('Geen velden om te updaten', 'NO_DATA', 400);
        }

        $tierModel->update($tierId, $tenantId, $data);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.updated', 'tier', $tierId, $data);

        Response::success([
            'message' => 'Tier bijgewerkt',
            'tier_id' => $tierId,
        ]);

    } elseif ($action === 'delete') {
        $tierId = (int) ($input['tier_id'] ?? 0);
        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Tier niet gevonden', 'NOT_FOUND', 404);
        }

        $tierModel->delete($tierId, $tenantId);

        (new Audit($db))->log($tenantId, currentUserId(), 'tier.deleted', 'tier', $tierId, [
            'name' => $tier['name'],
        ]);

        Response::success([
            'message' => 'Tier verwijderd',
            'tier_id' => $tierId,
        ]);

    } else {
        Response::error('Ongeldige actie. Gebruik: create, update, delete', 'INVALID_ACTION', 400);
    }

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
