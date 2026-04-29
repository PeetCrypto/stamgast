<?php
declare(strict_types=1);

/**
 * Superadmin Platform Settings API
 *
 * GET  /api/superadmin/settings          → get all settings (masked)
 * POST /api/superadmin/settings          → update settings
 *      body: { action: 'update', settings: { key: value, ... } }
 */

require_once __DIR__ . '/../../models/PlatformSetting.php';

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($db);
        break;

    case 'POST':
        handlePost($db);
        break;

    default:
        Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// ─── Handlers ────────────────────────────────────────────────────────────────

function handleGet(PDO $db): void
{
    $model = new PlatformSetting($db);
    $settings = $model->getAllMasked();

    Response::success([
        'settings' => $settings,
    ]);
}

function handlePost(PDO $db): void
{
    $input = getJsonInput();
    $action = $input['action'] ?? '';

    if ($action !== 'update') {
        Response::error('Invalid action. Use action: "update"', 'INVALID_ACTION', 400);
    }

    $rawSettings = $input['settings'] ?? [];
    if (!is_array($rawSettings) || empty($rawSettings)) {
        Response::error('No settings provided', 'NO_SETTINGS', 400);
    }

    // Validate keys against whitelist
    $allowedKeys = PlatformSetting::ALLOWED_KEYS;
    foreach (array_keys($rawSettings) as $key) {
        if (!in_array($key, $allowedKeys, true)) {
            Response::error("Setting key '{$key}' is not allowed", 'INVALID_KEY', 400);
        }
    }

    // Validate mollie_mode_default value
    if (isset($rawSettings['mollie_mode_default'])) {
        $validModes = ['mock', 'test', 'live'];
        if (!in_array($rawSettings['mollie_mode_default'], $validModes, true)) {
            Response::error('mollie_mode_default must be one of: mock, test, live', 'INVALID_MODE', 400);
        }
    }

    // Persist each setting
    $model = new PlatformSetting($db);
    $updated = [];
    $errors = [];

    foreach ($rawSettings as $key => $value) {
        // Cast to string
        $value = (string) $value;

        // Skip empty values for secret fields (means "keep current")
        $secretKeys = ['mollie_connect_api_key', 'mollie_connect_client_secret'];
        if (in_array($key, $secretKeys, true) && $value === '') {
            // Don't overwrite secrets with empty — user wants to keep existing
            continue;
        }

        if ($model->set($key, $value)) {
            $updated[] = $key;
        } else {
            $errors[] = "Failed to save {$key}";
        }
    }

    // Audit log
    if (!empty($updated)) {
        $audit = new Audit($db);
        $audit->log(
            0,
            $_SESSION['user_id'] ?? 0,
            'platform_settings.updated',
            'platform_setting',
            0,
            ['updated_keys' => $updated]
        );
    }

    if (!empty($errors)) {
        Response::error('Some settings failed to save: ' . implode(', ', $errors), 'PARTIAL_SAVE', 500);
    }

    Response::success([
        'updated' => $updated,
    ]);
}
