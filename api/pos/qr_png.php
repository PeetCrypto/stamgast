<?php
declare(strict_types=1);

/**
 * GET /api/pos/qr_png?token={session_token}
 *
 * Generates a POS payment QR code as PNG image.
 * Uses the SAME external API (api.qrserver.com) as the join QR
 * on /admin/settings — proven to be scannable by the guest scanner.
 *
 * Auth: bartender+ (enforced by router)
 */

require_once __DIR__ . '/../../models/PaymentSession.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

$tenantId = currentTenantId();
if ($tenantId === null) {
    http_response_code(401);
    exit('Unauthorized');
}

$db = Database::getInstance()->getConnection();
$sessionModel = new PaymentSession($db);
$session = $sessionModel->findByTokenAndTenant($token, $tenantId);

if ($session === null) {
    http_response_code(404);
    exit('Session not found');
}

// Build the QR data: POS:{session_token}
$qrData = 'POS:' . $token;

$size = 300;

// --- Output headers ---
header('Content-Type: image/png');
header('Cache-Control: private, max-age=300');

// --- Generate QR using the SAME external API as generate_join_qr.php ---
$qrImage = fetchQrFromApi($qrData, $size);

if ($qrImage !== null) {
    echo $qrImage;
} else {
    // Fallback: generate with GD library if external API fails
    generateQrFallback($qrData, $size);
}

exit;

/**
 * Fetch QR code PNG from external API
 * Same function as in generate_join_qr.php
 */
function fetchQrFromApi(string $data, int $size): ?string
{
    // Primary: api.qrserver.com (free, no key needed, high quality)
    $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
        . '?size=' . $size . 'x' . $size
        . '&data=' . urlencode($data)
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
        . '&chl=' . urlencode($data)
        . '&choe=UTF-8'
        . '&chld=M|1';

    $image = curlFetch($googleUrl);
    if ($image !== null && strlen($image) > 100) {
        return $image;
    }

    return null;
}

/**
 * Fetch URL content via cURL
 */
function curlFetch(string $url): ?string
{
    if (!function_exists('curl_init')) {
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
 * Fallback: generate a simple QR using PHP QR code or text placeholder
 */
function generateQrFallback(string $data, int $size): void
{
    // Try phpqrcode if available
    $qrLib = __DIR__ . '/../../vendor/phpqrcode/qrlib.php';
    if (file_exists($qrLib)) {
        require_once $qrLib;
        QRcode::png($data, false, QR_ECLEVEL_M, 10, 2);
        return;
    }

    // Last resort: text placeholder
    $img = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $gray  = imagecolorallocate($img, 150, 150, 150);
    imagefill($img, 0, 0, $white);
    imagerectangle($img, 10, 10, $size - 10, $size - 10, $gray);

    $text = 'QR Code';
    $textWidth = imagefontwidth(3) * strlen($text);
    imagestring($img, 3, (int)(($size - $textWidth) / 2), (int)($size * 0.4), $text, $black);

    $short = substr($data, 0, 30) . '...';
    $urlWidth = imagefontwidth(2) * strlen($short);
    imagestring($img, 2, (int)(($size - $urlWidth) / 2), (int)($size * 0.55), $short, $gray);

    imagepng($img);
    imagedestroy($img);
}
