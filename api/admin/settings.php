<?php
declare(strict_types=1);

/**
 * Admin Settings API
 * GET  /api/admin/settings
 * POST /api/admin/settings  { brand_color, secondary_color, ... }
 */

// Initialize database connection
$db = Database::getInstance()->getConnection();

$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

$tenantModel = new Tenant($db);
$method      = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- GET CURRENT SETTINGS ---
    $tenant = $tenantModel->findById($tenantId);
    if (!$tenant) {
        Response::error('Tenant niet gevonden', 'NOT_FOUND', 404);
    }

    Response::success([
        'id'                 => (int) $tenant['id'],
        'name'               => $tenant['name'],
        'slug'               => $tenant['slug'],
        'brand_color'        => $tenant['brand_color'],
        'secondary_color'    => $tenant['secondary_color'],
        'logo_path'          => $tenant['logo_path'],
        'mollie_status'      => $tenant['mollie_status'],
        'whitelisted_ips'    => $tenant['whitelisted_ips'],
        'feature_push'       => (bool) ($tenant['feature_push'] ?? true),
        'feature_marketing'  => (bool) ($tenant['feature_marketing'] ?? true),
        'contact_name'       => $tenant['contact_name'] ?? '',
        'contact_email'      => $tenant['contact_email'] ?? '',
        'phone'              => $tenant['phone'] ?? '',
        'address'            => $tenant['address'] ?? '',
        'postal_code'        => $tenant['postal_code'] ?? '',
        'city'               => $tenant['city'] ?? '',
        'country'            => $tenant['country'] ?? 'Nederland',
        'verification_soft_limit'   => (int) ($tenant['verification_soft_limit'] ?? 15),
        'verification_hard_limit'   => (int) ($tenant['verification_hard_limit'] ?? 30),
        'verification_cooldown_sec' => (int) ($tenant['verification_cooldown_sec'] ?? 180),
        'verification_max_attempts' => (int) ($tenant['verification_max_attempts'] ?? 2),
    ]);

} elseif ($method === 'POST') {
    // --- UPDATE SETTINGS ---
    $input = getJsonInput();

    $data = [];

    // Brand colors (validate hex format)
    if (isset($input['brand_color'])) {
        $color = trim($input['brand_color']);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            Response::error('Ongeldig brand_color formaat (gebruik #RRGGBB)', 'VALIDATION_ERROR', 422);
        }
        $data['brand_color'] = $color;
    }

    if (isset($input['secondary_color'])) {
        $color = trim($input['secondary_color']);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            Response::error('Ongeldig secondary_color formaat (gebruik #RRGGBB)', 'VALIDATION_ERROR', 422);
        }
        $data['secondary_color'] = $color;
    }

    // Name
    if (isset($input['name'])) {
        $name = trim($input['name']);
        if ($name === '') {
            Response::error('Naam mag niet leeg zijn', 'VALIDATION_ERROR', 422);
        }
        $data['name'] = $name;
    }

    // Slug
    if (isset($input['slug'])) {
        $slug = trim($input['slug']);
        if ($slug !== '' && !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            Response::error('Ongeldig slug formaat (alleen kleine letters, cijfers, streepjes)', 'VALIDATION_ERROR', 422);
        }
        $data['slug'] = $slug;
    }

    // Mollie settings
    if (isset($input['mollie_api_key'])) {
        $data['mollie_api_key'] = trim($input['mollie_api_key']);
    }

    if (isset($input['mollie_status'])) {
        $status = trim($input['mollie_status']);
        if (!in_array($status, ['mock', 'test', 'live'], true)) {
            Response::error('Ongeldige Mollie status. Gebruik: mock, test, live', 'VALIDATION_ERROR', 422);
        }
        $data['mollie_status'] = $status;
    }

    // Whitelisted IPs
    if (isset($input['whitelisted_ips'])) {
        $data['whitelisted_ips'] = trim($input['whitelisted_ips']);
    }

    // NOTE: feature_push and feature_marketing are NOT editable by admin.
    // Only the Super-Admin can toggle these via /api/superadmin/tenants
    // This ensures platform-level control over module availability.

    // Logo removal: clear logo_path, delete file from disk, update session
    if (array_key_exists('logo_path', $input) && $input['logo_path'] === '') {
        // Get current logo path before clearing
        $tenant = $tenantModel->findById($tenantId);
        $oldLogoPath = $tenant['logo_path'] ?? '';

        $data['logo_path'] = '';

        // Delete the physical file from disk
        if ($oldLogoPath) {
            $relativePath = ltrim(str_replace(
                defined('BASE_URL') ? BASE_URL : '',
                '',
                $oldLogoPath
            ), '/');
            $oldFile = defined('ROOT_PATH')
                ? ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
                : __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (file_exists($oldFile) && is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        // Update session immediately so header reflects the change
        $_SESSION['tenant_logo'] = '';
        if (isset($_SESSION['tenant'])) {
            $_SESSION['tenant']['logo_path'] = '';
        }
    }

    // NAW fields
    $nawFields = ['contact_name', 'contact_email', 'phone', 'address', 'postal_code', 'city', 'country'];
    foreach ($nawFields as $field) {
        if (isset($input[$field])) {
            $data[$field] = trim($input[$field]);
        }
    }

    // Verification limits (gated onboarding)
    $verificationFields = ['verification_soft_limit', 'verification_hard_limit', 'verification_cooldown_sec', 'verification_max_attempts'];
    foreach ($verificationFields as $field) {
        if (isset($input[$field])) {
            $val = (int) $input[$field];
            if ($val < 0) {
                Response::error($field . ' moet een positief getal zijn', 'VALIDATION_ERROR', 422);
            }
            // Enforce platform minimums
            if ($field === 'verification_soft_limit' && defined('VERIFICATION_SOFT_LIMIT_MIN')) {
                $val = max($val, VERIFICATION_SOFT_LIMIT_MIN);
            }
            if ($field === 'verification_hard_limit' && defined('VERIFICATION_HARD_LIMIT_MIN')) {
                $val = max($val, VERIFICATION_HARD_LIMIT_MIN);
            }
            if ($field === 'verification_cooldown_sec' && defined('VERIFICATION_COOLDOWN_MAX')) {
                $val = min($val, VERIFICATION_COOLDOWN_MAX);
            }
            $data[$field] = $val;
        }
    }

    if (empty($data)) {
        Response::error('Geen velden om te updaten', 'NO_DATA', 400);
    }

    $tenantModel->update($tenantId, $data);

    // Update session so header reflects changes immediately (without page reload)
    if (isset($data['brand_color'])) {
        $_SESSION['brand_color'] = $data['brand_color'];
    }
    if (isset($data['secondary_color'])) {
        $_SESSION['secondary_color'] = $data['secondary_color'];
    }
    if (isset($data['name'])) {
        $_SESSION['tenant_name'] = $data['name'];
    }
    if (isset($data['whitelisted_ips'])) {
        $_SESSION['whitelisted_ips'] = $data['whitelisted_ips'];
    }

    (new Audit($db))->log($tenantId, currentUserId(), 'settings.updated', 'tenant', $tenantId, ['fields' => array_keys($data)]);

    Response::success([
        'message' => 'Instellingen opgeslagen',
        'updated_fields' => array_keys($data),
    ]);

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
