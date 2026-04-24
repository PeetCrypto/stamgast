<?php
declare(strict_types=1);

/**
 * Tenant Logo Upload API
 * POST /api/upload/logo
 * Handles image upload for tenant branding
 *
 * NOTE: Auth/CSRF is handled by the router (index.php):
 *   - Session and tenant check are done in the 'upload' case of handleApiRoute()
 *   - CSRF is skipped for the 'upload' group (multipart/form-data incompatibility)
 *   - $tenantId is already available from the router scope
 */

/**
 * Detect and remove baked-in checkerboard transparency from PNG files.
 *
 * Some image editors (Photoshop, GIMP, Canva, Figma) export PNGs where the
 * checkerboard "transparency indicator" grid is composited into the actual
 * pixel data instead of using true alpha-channel transparency. This results
 * in opaque white/gray squares visible on dark backgrounds.
 *
 * This function:
 * 1. Samples edge pixels to detect the checkerboard pattern
 * 2. Identifies the two alternating colors (typically white + light gray)
 * 3. Replaces those pixels with true transparency (alpha = 0)
 * 4. Re-saves the PNG with proper alpha channel preservation
 *
 * @param string $filePath Absolute path to the PNG file
 * @return bool True if checkerboard was detected and removed, false otherwise
 */
function cleanCheckerboardTransparency(string $filePath, string $mimeType = 'image/png'): bool
{
    // GD library check
    if (!function_exists('imagecreatefrompng')) {
        return false;
    }

    // Load image based on format
    if ($mimeType === 'image/webp') {
        if (!function_exists('imagecreatefromwebp')) return false;
        $img = @imagecreatefromwebp($filePath);
    } else {
        $img = @imagecreatefrompng($filePath);
    }

    if (!$img) {
        return false;
    }

    $width  = imagesx($img);
    $height = imagesy($img);

    // Skip tiny images
    if ($width < 20 || $height < 20) {
        imagedestroy($img);
        return false;
    }

    // Enable alpha blending and save alpha
    imagesavealpha($img, true);
    imagealphablending($img, false);

    // Step 1: Sample edge pixels to detect checkerboard pattern.
    // Checkerboard patterns from editors typically use two alternating colors:
    //   Color A: white-ish   (RGB ~255,255,255)
    //   Color B: light gray  (RGB ~204,204,204 or ~192,192,192)
    $edgePixels = [];
    $sampleMargin = min(50, (int)($width * 0.1), (int)($height * 0.1));

    // Sample top edge, bottom edge, left edge, right edge
    for ($x = 0; $x < $sampleMargin; $x++) {
        $edgePixels[] = getPixelRGBA($img, $x, 0);
        $edgePixels[] = getPixelRGBA($img, $x, $height - 1);
    }
    for ($y = 0; $y < $sampleMargin; $y++) {
        $edgePixels[] = getPixelRGBA($img, 0, $y);
        $edgePixels[] = getPixelRGBA($img, $width - 1, $y);
    }

    // Step 2: Check if edge pixels are mostly opaque (no true transparency)
    $opaqueCount = 0;
    $transparentCount = 0;
    foreach ($edgePixels as $px) {
        if ($px['a'] > 200) $opaqueCount++;
        elseif ($px['a'] < 50) $transparentCount++;
    }

    // If edges already have true transparency, no checkerboard issue
    if ($transparentCount > $opaqueCount) {
        imagedestroy($img);
        return false;
    }

    // Step 3: Cluster edge pixel colors to find the two dominant checkerboard colors
    $colorClusters = [];
    foreach ($edgePixels as $px) {
        if ($px['a'] < 200) continue; // Skip semi-transparent
        // Quantize to reduce noise (round to nearest 8)
        $qr = (int)(round($px['r'] / 8) * 8);
        $qg = (int)(round($px['g'] / 8) * 8);
        $qb = (int)(round($px['b'] / 8) * 8);
        $key = "{$qr},{$qg},{$qb}";
        if (!isset($colorClusters[$key])) {
            $colorClusters[$key] = ['count' => 0, 'r' => $qr, 'g' => $qg, 'b' => $qb];
        }
        $colorClusters[$key]['count']++;
    }

    // Sort by frequency descending
    uasort($colorClusters, fn($a, $b) => $b['count'] <=> $a['count']);
    $topColors = array_slice($colorClusters, 0, 5);

    // Step 4: Check if the top 2 colors look like a checkerboard pattern
    // Both should be light (high RGB values) and close to each other
    $topColorsArr = array_values($topColors);
    if (count($topColorsArr) < 2) {
        imagedestroy($img);
        return false;
    }

    $c1 = $topColorsArr[0];
    $c2 = $topColorsArr[1];

    // Both colors must be light (average RGB > 180)
    $avg1 = ($c1['r'] + $c1['g'] + $c1['b']) / 3;
    $avg2 = ($c2['r'] + $c2['g'] + $c2['b']) / 3;

    if ($avg1 < 180 || $avg2 < 180) {
        imagedestroy($img);
        return false;
    }

    // The two colors should be close but distinct (difference between 5 and 80)
    $colorDiff = abs($avg1 - $avg2);
    if ($colorDiff < 5 || $colorDiff > 80) {
        imagedestroy($img);
        return false;
    }

    // Step 5: Replace all pixels matching either checkerboard color with true transparency.
    // We skip the grid-alignment check because the checkerboard grid offset is unknown
    // and the combination of (opaque edges + two light similar colors) is already strong evidence.
    $tolerance = 30; // Color distance tolerance

    $pixelsChanged = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $px = getPixelRGBA($img, $x, $y);

            // Only process fully opaque pixels
            if ($px['a'] < 200) continue;

            // Check if this pixel matches either checkerboard color
            $dist1 = colorDistance($px, $c1);
            $dist2 = colorDistance($px, $c2);

            if ($dist1 <= $tolerance || $dist2 <= $tolerance) {
                // Make this pixel fully transparent (GD alpha: 127 = fully transparent)
                $newColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
                imagesetpixel($img, $x, $y, $newColor);
                $pixelsChanged++;
            }
        }
    }

    // If we changed less than 5% of pixels, it's probably not a checkerboard
    $totalPixels = $width * $height;
    $changeRatio = $pixelsChanged / $totalPixels;

    if ($changeRatio < 0.05) {
        imagedestroy($img);
        return false;
    }

    // Step 6: Save the cleaned image in its original format
    if ($mimeType === 'image/webp') {
        imagewebp($img, $filePath, 80);
    } else {
        imagepng($img, $filePath, 9);
    }
    imagedestroy($img);

    return true;
}

/**
 * Get RGBA values for a pixel.
 *
 * Returns alpha in STANDARD range (0 = fully transparent, 255 = fully opaque)
 * by converting from GD's inverted scale (0 = opaque, 127 = transparent).
 */
function getPixelRGBA($img, int $x, int $y): array
{
    $colorIndex = imagecolorat($img, $x, $y);
    $rgba = imagecolorsforindex($img, $colorIndex);
    // Convert GD alpha (0=opaque, 127=transparent) to standard (0=transparent, 255=opaque)
    $standardAlpha = (int)round((127 - $rgba['alpha']) / 127 * 255);
    return [
        'r' => $rgba['red'],
        'g' => $rgba['green'],
        'b' => $rgba['blue'],
        'a' => $standardAlpha,
    ];
}

/**
 * Calculate Euclidean color distance between a pixel and a reference color.
 */
function colorDistance(array $pixel, array $reference): float
{
    return sqrt(
        pow($pixel['r'] - $reference['r'], 2) +
        pow($pixel['g'] - $reference['g'], 2) +
        pow($pixel['b'] - $reference['b'], 2)
    );
}

// Check if file was uploaded
if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'Geen bestand geüpload';
    if (isset($_FILES['logo'])) {
        $errorMsg .= ' (foutcode: ' . $_FILES['logo']['error'] . ')';
    }
    Response::error($errorMsg, 'UPLOAD_ERROR', 400);
}

$file = $_FILES['logo'];

// Validate file type via MIME sniffing (not extension)
$allowedTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

// SVG files are often detected as text/xml or text/plain by finfo
// so we do an additional check based on file content
$isSvg = false;
if ($mimeType === 'text/xml' || $mimeType === 'text/plain' || $mimeType === 'application/xml') {
    $content = file_get_contents($file['tmp_name']);
    if ($content !== false && preg_match('/<svg[\s>]/i', $content)) {
        $isSvg = true;
        $mimeType = 'image/svg+xml';
    }
}

if (!in_array($mimeType, $allowedTypes, true)) {
    Response::error('Alleen PNG, JPG, WebP en SVG toegestaan (gedetecteerd: ' . $mimeType . ')', 'INVALID_TYPE', 400);
}

// Validate file size (max 2MB)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    Response::error('Bestand te groot (max 2MB, jouw bestand is ' . round($file['size'] / 1024 / 1024, 1) . 'MB)', 'SIZE_ERROR', 400);
}

// Extra SVG security: block SVGs containing scripts or external resources
if ($mimeType === 'image/svg+xml') {
    $svgContent = file_get_contents($file['tmp_name']);
    if ($svgContent === false) {
        Response::error('Kon SVG-bestand niet lezen', 'READ_ERROR', 400);
    }
    // Block dangerous patterns (script tags, event handlers, external references)
    $dangerous = '/<script|javascript:|on\w+\s*=|<iframe|<embed|<object|\bexternalResourcesRequired\b/i';
    if (preg_match($dangerous, $svgContent)) {
        Response::error('SVG bevat niet-toegestane elementen (scripts of event handlers)', 'SVG_UNSAFE', 400);
    }
}

// Get tenant (for old logo cleanup)
$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);
$oldLogoPath = $tenant['logo_path'] ?? '';

// Generate unique filename
$extensionMap = [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/webp'    => 'webp',
    'image/svg+xml' => 'svg',
];
$extension = $extensionMap[$mimeType] ?? 'png';
$newFilename = "tenant_{$tenantId}_" . time() . '.' . $extension;

// Physical path: project root / public / uploads / logos /
$uploadDir = PUBLIC_PATH . 'uploads' . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$newFilePath = $uploadDir . $newFilename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
    Response::error('Kon bestand niet opslaan. Controleer schrijfrechten voor: ' . $uploadDir, 'SAVE_ERROR', 500);
}

// Verify file actually exists on disk
if (!file_exists($newFilePath)) {
    Response::error('Bestand niet gevonden na upload. Pad: ' . $newFilePath, 'VERIFY_ERROR', 500);
}

// Post-process: detect and remove baked-in checkerboard transparency patterns
// Some image editors export images with the checkerboard "transparency grid" baked
// into the actual pixel data instead of using true alpha-channel transparency.
if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
    $cleaned = cleanCheckerboardTransparency($newFilePath, $mimeType);
    if ($cleaned) {
        error_log("[LOGO UPLOAD] Checkerboard pattern detected and removed for tenant {$tenantId} ({$mimeType})");
    }
}

// Build the URL that the browser will use to request this file.
// Since public/ is served as web root via .htaccess and the static file server
// in index.php, the URL must include /public/ so it either:
//   a) bypasses the rewrite (.htaccess RewriteCond) or
//   b) is served by the static file handler in index.php
$logoUrl = BASE_URL . '/public/uploads/logos/' . $newFilename;

// Persist in database
$tenantModel->update($tenantId, ['logo_path' => $logoUrl]);

// Update session for immediate UI reflection (no re-login needed)
$_SESSION['tenant_logo'] = $logoUrl;
if (isset($_SESSION['tenant'])) {
    $_SESSION['tenant']['logo_path'] = $logoUrl;
}

// Log audit trail
(new Audit($db))->log($tenantId, currentUserId(), 'logo.uploaded', 'tenant', $tenantId, [
    'old_path' => $oldLogoPath,
    'new_path' => $logoUrl,
    'filename' => $newFilename,
    'size_bytes' => $file['size'],
]);

// Delete old logo file from disk (if different from new)
if ($oldLogoPath && $oldLogoPath !== $logoUrl) {
    // Extract the filesystem-relative path from the URL
    // e.g. "/public/uploads/logos/tenant_1_123.png" -> "public/uploads/logos/tenant_1_123.png"
    $relativePath = ltrim(str_replace(BASE_URL, '', $oldLogoPath), '/');
    $oldFile = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (file_exists($oldFile) && is_file($oldFile)) {
        @unlink($oldFile);
    }
}

Response::success([
    'logo_url' => $logoUrl,
    'message' => 'Logo opgeslagen',
]);