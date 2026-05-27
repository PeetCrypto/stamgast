<?php
declare(strict_types=1);

/**
 * POST /api/pos/create_session
 * Bartender creates a payment session with amounts, returns QR data for guest to scan.
 *
 * Auth: bartender+ (enforced by router)
 * Middleware: CSRF, IP whitelist (enforced by router)
 *
 * Request:  { amount_alc_cents: int, amount_food_cents: int }
 * Response: { session_token, qr_data, qr_png_url, qr_png_base64, expires_at }
 */

require_once __DIR__ . '/../../models/PaymentSession.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../services/QrService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

$amountAlc  = (int) ($input['amount_alc_cents'] ?? 0);
$amountFood = (int) ($input['amount_food_cents'] ?? 0);

// --- Input validation ---
if ($amountAlc < 0 || $amountFood < 0) {
    Response::error('Bedragen mogen niet negatief zijn', 'VALIDATION_ERROR', 422);
}
if ($amountAlc === 0 && $amountFood === 0) {
    Response::error('Er moet minimaal één bedrag ingevuld zijn', 'VALIDATION_ERROR', 422);
}

$tenantId    = currentTenantId();
$bartenderId = currentUserId();

if ($tenantId === null || $bartenderId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();

    // Expire old pending sessions for this tenant (cleanup)
    $sessionModel = new PaymentSession($db);
    $sessionModel->expireOldSessions($tenantId);

    // Create new session
    $session = $sessionModel->create($tenantId, $bartenderId, $amountAlc, $amountFood);

    if (!$session) {
        Response::internalError('Kon sessie niet aanmaken');
    }

    // Generate QR code for this session
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);
    $tenantName = $tenant ? ($tenant['name'] ?? '') : '';
    $qrService = new QrService($db);
    $qr = $qrService->generatePosQr($session['session_token'], $tenantId, $tenantName);

    // Generate QR PNG server-side (same method as join QR on /admin/settings)
    $qrPngBase64 = generateQrPngBase64($qr['qr_data'], 300);

    // Audit
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $bartenderId,
        'pos.session_created',
        'pos_payment_session',
        (int) $session['id'],
        [
            'amount_alc_cents'  => $amountAlc,
            'amount_food_cents' => $amountFood,
        ]
    );

    Response::success([
        'session_token' => $session['session_token'],
        'session_id'    => (int) $session['id'],
        'qr_data'       => $qr['qr_data'],
        'qr_png_url'    => BASE_URL . '/api/pos/qr_png?token=' . urlencode($session['session_token']),
        'qr_png_base64' => $qrPngBase64,
        'expires_at'    => $qr['expires_at'],
        'amount_alc_cents'  => $amountAlc,
        'amount_food_cents' => $amountFood,
    ]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Sessie aanmaken mislukt: ' . $e->getMessage());
    }
    Response::internalError('Sessie aanmaken mislukt');
}

/**
 * Generate QR code as base64-encoded PNG data URI
 * Uses external API (api.qrserver.com) — same as join QR on /admin/settings
 */
function generateQrPngBase64(string $data, int $size): string
{
    // Try external API first (same as generate_join_qr.php)
    $pngData = fetchQrPng($data, $size);
    if ($pngData !== null) {
        return 'data:image/png;base64,' . base64_encode($pngData);
    }

    // Fallback: return empty string (frontend will use qr_png_url or qr_data)
    return '';
}

/**
 * Fetch QR PNG from external API
 */
function fetchQrPng(string $data, int $size): ?string
{
    // Primary: api.qrserver.com
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

    // Fallback: Google Charts
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
 * cURL fetch
 */
function curlFetch(string $url): ?string
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10],
            'ssl'  => ['verify_peer' => !APP_DEBUG, 'verify_peer_name' => !APP_DEBUG],
        ]);
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
        CURLOPT_SSL_VERIFYPEER => !APP_DEBUG, // false in dev (no CA bundle), true in production
        CURLOPT_USERAGENT      => 'REGULR.vip/1.0',
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($data !== false && $httpCode === 200) ? $data : null;
}
