<?php
declare(strict_types=1);

/**
 * Dynamic PWA Manifest
 * Generates a web app manifest with tenant-specific branding
 * Reference: https://developer.mozilla.org/en-US/docs/Web/Manifest
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/helpers.php';

// Start session for tenant context
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine tenant branding
$tenantId = currentTenantId();
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$brandColor = $_SESSION['brand_color'] ?? '#FFC107';
$secondaryColor = $_SESSION['secondary_color'] ?? '#FF9800';

// If tenant is logged in, fetch latest branding from DB
if ($tenantId) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT `name`, `brand_color`, `secondary_color` FROM `tenants` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $tenantName = $tenant['name'];
            $brandColor = $tenant['brand_color'];
            $secondaryColor = $tenant['secondary_color'];
        }
    } catch (\Throwable $e) {
        // Fall back to session/defaults
    }
}

// Determine base URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "{$scheme}://{$host}";

// Build manifest
$manifest = [
    'name'             => $tenantName . ' - STAMGAST',
    'short_name'       => $tenantName,
    'description'      => $tenantName . ' Loyaliteitsplatform',
    'start_url'        => '/',
    'display'          => 'standalone',
    'background_color' => '#0f0f0f',
    'theme_color'      => $brandColor,
    'orientation'      => 'portrait-primary',
    'lang'             => 'nl',
    'scope'            => '/',
    'icons'            => [
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=72',
            'sizes'   => '72x72',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=96',
            'sizes'   => '96x96',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=128',
            'sizes'   => '128x128',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=144',
            'sizes'   => '144x144',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=152',
            'sizes'   => '152x152',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=384',
            'sizes'   => '384x384',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=512',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'categories' => ['food', 'lifestyle', 'shopping'],
    'shortcuts'  => [
        [
            'name'        => 'QR Code',
            'short_name'  => 'QR',
            'description' => 'Toon je QR code',
            'url'         => '/qr',
            'icons'       => [
                ['src' => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=96', 'sizes' => '96x96'],
            ],
        ],
        [
            'name'        => 'Opwaarderen',
            'short_name'  => 'Wallet',
            'description' => 'Opwaarderen wallet',
            'url'         => '/wallet',
            'icons'       => [
                ['src' => '/api/assets/generate_pwa_icon?tenant_id=' . ($tenantId ?? 0) . '&size=96', 'sizes' => '96x96'],
            ],
        ],
    ],
];

// Output as JSON with proper caching headers
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, max-age=300'); // 5 min cache
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
