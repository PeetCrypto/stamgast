<?php
declare(strict_types=1);

/**
 * Admin Tiers/Package API
 * GET  /api/admin/tiers
 * POST /api/admin/tiers  { action: 'create'|'update'|'delete'|'toggle', ... }
 */

require_once __DIR__ . '/../../models/LoyaltyTier.php';
require_once __DIR__ . '/../../models/Tenant.php';

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
            'model_type'            => $tier['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT,
            'bonus_percentage'      => (float) ($tier['bonus_percentage'] ?? 0),
            'bonus_cents'           => (int) ($tier['bonus_cents'] ?? 0),
            'alcohol_discount_perc' => (float) $tier['alcohol_discount_perc'],
            'food_discount_perc'    => (float) $tier['food_discount_perc'],
            'points_multiplier'     => (float) $tier['points_multiplier'],
            'is_active'             => (int) $tier['is_active'],
            'sort_order'            => (int) $tier['sort_order'],
            'is_test_package'       => (int) ($tier['is_test_package'] ?? 0),
        ];
    }, $tiers);

    $tenant = (new Tenant($db))->findById($tenantId);
    $lockedModel = $tenant['tier_model_type'] ?? null;

    // Self-heal: if tiers exist but tenant model was never locked, infer from first tier
    if (!$lockedModel && !empty($tiers)) {
        $lockedModel = $tiers[0]['model_type'] ?? null;
        if ($lockedModel) {
            try {
                (new Tenant($db))->update($tenantId, ['tier_model_type' => $lockedModel]);
            } catch (\Throwable $e) {
                error_log('[tiers] Auto-lock tier_model_type failed: ' . $e->getMessage());
            }
        }
    }

    Response::success([
        'tiers' => $result,
        'tier_model_type' => $lockedModel,
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
        // Test packages are auto-managed via Test Modus — admins cannot create them
        if (isset($input['is_test_package'])) {
            Response::error('Het aanmaken van testpakketten is niet toegestaan', 'TEST_PACKAGE_LOCKED', 403);
        }
        $name               = trim($input['name'] ?? '');
        $minDepositCents    = (int) ($input['min_deposit_cents'] ?? 0);
        $topupAmountCents   = (int) ($input['topup_amount_cents'] ?? LoyaltyTier::MIN_TOPUP_CENTS);
        // Enforce tenant-level model type lock
        $tenant = (new Tenant($db))->findById($tenantId);
        $lockedModel = $tenant['tier_model_type'] ?? null;
        if ($lockedModel !== null) {
            $modelType = $lockedModel;
        } else {
            $modelType = $input['model_type'] ?? LoyaltyTier::MODEL_DISCOUNT;
            if (!in_array($modelType, [LoyaltyTier::MODEL_DISCOUNT, LoyaltyTier::MODEL_BONUS])) {
                $modelType = LoyaltyTier::MODEL_DISCOUNT;
            }
            try {
                (new Tenant($db))->update($tenantId, ['tier_model_type' => $modelType]);
            } catch (\Throwable $e) {
                error_log('[tiers] Failed to lock tier_model_type: ' . $e->getMessage());
                // Non-fatal: tier creation continues without lock
            }
        }
        $bonusPercentage    = (float) ($input['bonus_percentage'] ?? 0);
        $bonusCents         = (int) ($input['bonus_cents'] ?? 0);
        $alcDiscount        = (float) ($input['alcohol_discount_perc'] ?? 0);
        $foodDiscount       = (float) ($input['food_discount_perc'] ?? 0);
        $pointsMultiplier   = (float) ($input['points_multiplier'] ?? 1.0);
        $sortOrder          = (int) ($input['sort_order'] ?? $tierModel->getNextSortOrder($tenantId));
        $isActive           = isset($input['is_active']) ? (int) $input['is_active'] : 1;

        // Validate model type
        if (!in_array($modelType, [LoyaltyTier::MODEL_DISCOUNT, LoyaltyTier::MODEL_BONUS])) {
            Response::error('Ongeldig model type. Gebruik: discount of bonus', 'VALIDATION_ERROR', 422);
        }

        // Validate
        if ($name === '') {
            Response::error('Naam is verplicht', 'VALIDATION_ERROR', 422);
        }
        // Duplicate name check (within same tenant)
        $existingTiers = $tierModel->getByTenant($tenantId);
        foreach ($existingTiers as $existing) {
            if (mb_strtolower(trim($existing['name'])) === mb_strtolower($name)) {
                Response::error('Er bestaat al een pakket met de naam "' . htmlspecialchars($name) . '"', 'DUPLICATE_NAME', 422);
            }
        }
        if ($topupAmountCents < LoyaltyTier::MIN_TOPUP_CENTS) {
            Response::error('Opwaardeerbedrag moet minimaal €' . (LoyaltyTier::MIN_TOPUP_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
        }
        if ($topupAmountCents > DEPOSIT_MAX_CENTS) {
            Response::error('Opwaardeerbedrag mag maximaal €' . (DEPOSIT_MAX_CENTS / 100) . ' zijn', 'VALIDATION_ERROR', 422);
        }

        // Model-specific validation
        if ($modelType === LoyaltyTier::MODEL_BONUS) {
            if ($bonusCents < 0 || $bonusCents > 50000) {
                Response::error('Bonus bedrag moet tussen €0 en €500 zijn', 'VALIDATION_ERROR', 422);
            }
            if ($bonusPercentage < 0 || $bonusPercentage > LoyaltyTier::BONUS_MAX) {
                Response::error('Bonus percentage moet tussen 0% en ' . LoyaltyTier::BONUS_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
            $alcDiscount = 0;
            if ($foodDiscount < 0 || $foodDiscount > FOOD_DISCOUNT_MAX) {
                Response::error('Eten korting moet tussen 0% en ' . FOOD_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
        } else {
            if ($alcDiscount < 0 || $alcDiscount > ALCOHOL_DISCOUNT_MAX) {
                Response::error('Alcohol korting moet tussen 0% en ' . ALCOHOL_DISCOUNT_MAX . '% zijn (wettelijk maximum)', 'VALIDATION_ERROR', 422);
            }
            if ($foodDiscount < 0 || $foodDiscount > FOOD_DISCOUNT_MAX) {
                Response::error('Eten korting moet tussen 0% en ' . FOOD_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
        }

        if ($pointsMultiplier < 0.5 || $pointsMultiplier > 10) {
            Response::error('Punten multiplier moet tussen 0.5 en 10 zijn', 'VALIDATION_ERROR', 422);
        }

        $tierId = $tierModel->create([
            'tenant_id'             => $tenantId,
            'name'                  => $name,
            'min_deposit_cents'     => $minDepositCents,
            'topup_amount_cents'    => $topupAmountCents,
            'model_type'            => $modelType,
            'bonus_percentage'      => $bonusPercentage,
            'bonus_cents'           => $bonusCents,
            'alcohol_discount_perc' => $alcDiscount,
            'food_discount_perc'    => $foodDiscount,
            'points_multiplier'     => $pointsMultiplier,
            'is_active'             => $isActive,
            'sort_order'            => $sortOrder,
        ]);

        try {
            (new Audit($db))->log($tenantId, currentUserId(), 'tier.created', 'tier', $tierId, [
                'name' => $name,
                'topup_amount_cents' => $topupAmountCents,
            ]);
        } catch (\Throwable $e) {
            error_log('[tiers] Audit log failed (create): ' . $e->getMessage());
        }

        Response::success([
            'message' => 'Pakket aangemaakt',
            'tier_id' => $tierId,
        ], 201);

    } elseif ($action === 'update') {
        $tierId = (int) ($input['tier_id'] ?? 0);
        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Pakket niet gevonden', 'NOT_FOUND', 404);
        }

        // Test packages are auto-managed via Test Modus — admins cannot edit them
        if (!empty($tier['is_test_package'])) {
            Response::error('Het testpakket wordt automatisch beheerd via de Test Modus en kan niet worden bewerkt.', 'TEST_PACKAGE_LOCKED', 403);
        }

        $data = [];
        if (isset($input['name'])) {
            $data['name'] = trim($input['name']);
            if ($data['name'] === '') {
                Response::error('Naam mag niet leeg zijn', 'VALIDATION_ERROR', 422);
            }
            // Duplicate name check (within same tenant, exclude current tier)
            $existingTiers = $tierModel->getByTenant($tenantId);
            foreach ($existingTiers as $existing) {
                if (((int) $existing['id']) !== $tierId
                    && mb_strtolower(trim($existing['name'])) === mb_strtolower($data['name'])
                ) {
                    Response::error('Er bestaat al een pakket met de naam "' . htmlspecialchars($data['name']) . '"', 'DUPLICATE_NAME', 422);
                }
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
        // Model type is locked on tenant level — cannot change per tier
        if (isset($input['model_type'])) {
            unset($input['model_type']);
        }
        if (isset($input['bonus_percentage'])) {
            $val = (float) $input['bonus_percentage'];
            if ($val < 0 || $val > LoyaltyTier::BONUS_MAX) {
                Response::error('Bonus percentage moet tussen 0% en ' . LoyaltyTier::BONUS_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
            $data['bonus_percentage'] = $val;
        }
        if (isset($input['bonus_cents'])) {
            $val = (int) $input['bonus_cents'];
            if ($val < 0 || $val > 50000) {
                Response::error('Bonus bedrag moet tussen €0 en €500 zijn', 'VALIDATION_ERROR', 422);
            }
            $data['bonus_cents'] = $val;
        }
        if (isset($input['alcohol_discount_perc'])) {
            $val = (float) $input['alcohol_discount_perc'];
            if ($val < 0 || $val > ALCOHOL_DISCOUNT_MAX) {
                Response::error('Alcohol korting moet tussen 0% en ' . ALCOHOL_DISCOUNT_MAX . '% zijn', 'VALIDATION_ERROR', 422);
            }
            if (isset($input['model_type']) && $input['model_type'] === LoyaltyTier::MODEL_BONUS) {
                Response::error('Alcohol korting is niet beschikbaar in het bonus model', 'VALIDATION_ERROR', 422);
            }
            if (!isset($input['model_type']) && ($tier['model_type'] ?? '') === LoyaltyTier::MODEL_BONUS) {
                Response::error('Alcohol korting is niet beschikbaar in het bonus model', 'VALIDATION_ERROR', 422);
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

        try {
            (new Audit($db))->log($tenantId, currentUserId(), 'tier.updated', 'tier', $tierId, $data);
        } catch (\Throwable $e) {
            error_log('[tiers] Audit log failed (update): ' . $e->getMessage());
        }

        Response::success([
            'message' => 'Pakket bijgewerkt',
            'tier_id' => $tierId,
        ]);

    } elseif ($action === 'toggle') {
        $tierId = (int) ($input['tier_id'] ?? 0);
        $active = (bool) ($input['is_active'] ?? false);

        if ($tierId <= 0) {
            Response::error('Ongeldig tier_id', 'INVALID_INPUT', 400);
        }

        $tier = $tierModel->findById($tierId, $tenantId);
        if (!$tier) {
            Response::error('Pakket niet gevonden', 'NOT_FOUND', 404);
        }

        // Test packages are auto-managed via Test Modus — admins cannot toggle them
        if (!empty($tier['is_test_package'])) {
            Response::error('Het testpakket wordt automatisch beheerd via de Test Modus en kan niet worden bewerkt.', 'TEST_PACKAGE_LOCKED', 403);
        }

        $tierModel->toggle($tierId, $tenantId, $active);

        try {
            (new Audit($db))->log($tenantId, currentUserId(), 'tier.toggled', 'tier', $tierId, [
                'is_active' => $active ? 1 : 0,
                'name' => $tier['name'],
            ]);
        } catch (\Throwable $e) {
            error_log('[tiers] Audit log failed (toggle): ' . $e->getMessage());
        }

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

        // Test packages are auto-managed via Test Modus — admins cannot delete them
        if (!empty($tier['is_test_package'])) {
            Response::error('Het testpakket wordt automatisch beheerd via de Test Modus en kan niet worden verwijderd.', 'TEST_PACKAGE_LOCKED', 403);
        }

        $tierModel->delete($tierId, $tenantId);

        try {
            (new Audit($db))->log($tenantId, currentUserId(), 'tier.deleted', 'tier', $tierId, [
                'name' => $tier['name'],
            ]);
        } catch (\Throwable $e) {
            error_log('[tiers] Audit log failed (delete): ' . $e->getMessage());
        }

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
