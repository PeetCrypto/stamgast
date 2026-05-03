<?php
declare(strict_types=1);

/**
 * GET /api/assets/generate_join_qr
 * Generate a QR code PNG for guest join URL: {BASE_URL}/j/{tenant_slug}
 *
 * Uses external QR API (api.qrserver.com) for reliable, scannable QR codes.
 * Falls back to a Google Charts API call if the primary fails.
 *
 * Query: ?size=300&download=1
 */

// Auth is already handled by index.php router (requireAdmin())
require_once __DIR__ . '/../../models/Tenant.php';

$size     = (int) ($_GET['size'] ?? 300);
$download = isset($_GET['download']);

if ($size < 100 || $size > 1000) {
    $size = 300;
}

// Get tenant from session
$tenantId = currentTenantId();
if ($tenantId === null) {
    http_response_code(400);
    exit('No tenant context');
}

$db          = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$tenant      = $tenantModel->findById($tenantId);

if ($tenant === null) {
    http_response_code(404);
    exit('Tenant not found');
}

$slug = $tenant['slug'] ?? null;
if (empty($slug)) {
    http_response_code(404);
    exit('Tenant has no slug');
}

// Build full join URL
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$joinUrl = "{$scheme}://{$host}" . BASE_URL . "/j/{$slug}";

// --- Output headers ---
$filename = 'qr-' . $slug . '.png';
header('Content-Type: image/png');
if ($download) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}
header('Cache-Control: private, max-age=300');

// --- Generate QR code using external API (reliable, scannable) ---
$qrImage = fetchQrFromApi($joinUrl, $size);

if ($qrImage !== null) {
    echo $qrImage;
} else {
    // Last resort: output a simple placeholder with the URL as text
    generateTextPlaceholder($joinUrl, $size, $tenant['name'] ?? 'REGULR.vip');
}

exit;

/**
 * Fetch QR code PNG from external API
 * Tries api.qrserver.com first, then Google Charts as fallback
 */
function fetchQrFromApi(string $url, int $size): ?string
{
    // Primary: api.qrserver.com (free, no key needed, high quality)
    $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
        . '?size=' . $size . 'x' . $size
        . '&data=' . urlencode($url)
        . '&format=png'
        . '&margin=10'
        . '&ecc=M';

    $image = curlFetch($apiUrl);
    if ($image !== null && strlen($image) > 100) {
        return $image;
    }

    // Fallback: Google Charts QR API
    $googleUrl = 'https://chart.googleapis.com/chart'
        . '?cht=qr'
        . '&chs=' . $size . 'x' . $size
        . '&chl=' . urlencode($url)
        . '&choe=UTF-8'
        . '&chld=M|1';

    $image = curlFetch($googleUrl);
    if ($image !== null && strlen($image) > 100) {
        return $image;
    }

    return null;
}

/**
 * Fetch URL content via cURL (works on shared hosting)
 */
function curlFetch(string $url): ?string
{
    if (!function_exists('curl_init')) {
        // Try file_get_contents as last resort
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $data = @file_get_contents($url, false, $ctx);
        return $data !== false ? $data : null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'REGULR.vip/1.0',
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($data !== false && $httpCode === 200) ? $data : null;
}

/**
 * Generate a simple text placeholder when QR APIs are unavailable
 */
function generateTextPlaceholder(string $url, int $size, string $name): void
{
    $img = imagecreatetruecolor($size, $size);
    if ($img === false) {
        return;
    }

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $gray  = imagecolorallocate($img, 150, 150, 150);

    imagefill($img, 0, 0, $white);

    // Draw border
    imagerectangle($img, 10, 10, $size - 10, $size - 10, $gray);

    // Draw text
    $fontSize = 3; // Built-in GD font
    $text = 'QR Code';
    $textWidth = imagefontwidth($fontSize) * strlen($text);
    $textX = (int) (($size - $textWidth) / 2);
    imagestring($img, $fontSize, $textX, (int) ($size * 0.35), $text, $black);

    // Show URL (truncated)
    $shortUrl = mb_substr($url, 0, 30) . (mb_strlen($url) > 30 ? '...' : '');
    $urlWidth = imagefontwidth(2) * strlen($shortUrl);
    $urlX = (int) (($size - $urlWidth) / 2);
    imagestring($img, 2, $urlX, (int) ($size * 0.55), $shortUrl, $gray);

    imagestring($img, 2, (int) (($size - imagefontwidth(2) * strlen('API unavailable')) / 2), (int) ($size * 0.7), 'API unavailable', $gray);

    imagepng($img);
    imagedestroy($img);
}
