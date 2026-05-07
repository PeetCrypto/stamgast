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
$tenantId   = currentTenantId();
$tenantSlug = '';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$brandColor = $_SESSION['brand_color'] ?? '#FFC107';
$secondaryColor = $_SESSION['secondary_color'] ?? '#FF9800';

// If tenant is logged in, fetch latest branding from DB
if ($tenantId) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT `id`, `slug`, `name`, `brand_color`, `secondary_color` FROM `tenants` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $tenantId      = (int) $tenant['id'];
            $tenantSlug    = $tenant['slug'];
            $tenantName    = $tenant['name'];
            $brandColor    = $tenant['brand_color'];
            $secondaryColor = $tenant['secondary_color'];
        }
    } catch (\Throwable $e) {
        // Fall back to session/defaults
    }
} elseif (!empty($_GET['slug'])) {
    // No session tenant — try to resolve tenant by slug (for unauthenticated guest pages)
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT `id`, `slug`, `name`, `brand_color`, `secondary_color` FROM `tenants` WHERE `slug` = :slug LIMIT 1');
        $stmt->execute([':slug' => $_GET['slug']]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $tenantId      = (int) $tenant['id'];
            $tenantSlug    = $tenant['slug'];
            $tenantName    = $tenant['name'];
            $brandColor    = $tenant['brand_color'];
            $secondaryColor = $tenant['secondary_color'];
        }
    } catch (\Throwable $e) {
        // Fall back to defaults
    }
}

// Build start_url: tenant-specific so the PWA always opens in the tenant's environment
$startUrl = !empty($tenantSlug) ? '/j/' . $tenantSlug : '/';

// Helper: build icon src URL
// When no tenant context (superadmin, unauthenticated), use the static favicon
// Otherwise use the dynamic branded icon endpoint
$iconSrc = function (int $size) use ($tenantId): string {
    if ($tenantId) {
        return '/api/assets/generate_pwa_icon?tenant_id=' . $tenantId . '&size=' . $size;
    }
    return '/icons/favicon.png';
};

// Build manifest
$manifest = [
    'name'             => $tenantName,
    'short_name'       => $tenantName,
    'description'      => $tenantName,
    'start_url'        => $startUrl,
    'display'          => 'standalone',
    'background_color' => '#0f0f0f',
    'theme_color'      => $brandColor,
    'orientation'      => 'portrait-primary',
    'lang'             => 'nl',
    'scope'            => '/',
    'icons'            => [
        ['src' => $iconSrc(72),  'sizes' => '72x72',    'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(96),  'sizes' => '96x96',    'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(128), 'sizes' => '128x128',  'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(144), 'sizes' => '144x144',  'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(152), 'sizes' => '152x152',  'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(192), 'sizes' => '192x192',  'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $iconSrc(384), 'sizes' => '384x384',  'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconSrc(512), 'sizes' => '512x512',  'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'categories' => ['food', 'lifestyle', 'shopping'],
    'shortcuts'  => [
        [
            'name'        => 'QR Code',
            'short_name'  => 'QR',
            'description' => 'Toon je QR code',
            'url'         => '/qr',
            'icons'       => [
                ['src' => $iconSrc(96), 'sizes' => '96x96'],
            ],
        ],
        [
            'name'        => 'Opwaarderen',
            'short_name'  => 'Wallet',
            'description' => 'Opwaarderen wallet',
            'url'         => '/wallet',
            'icons'       => [
                ['src' => $iconSrc(96), 'sizes' => '96x96'],
            ],
        ],
    ],
];

// Output as JSON with proper caching headers
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, max-age=300'); // 5 min cache
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
