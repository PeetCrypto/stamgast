<?php
declare(strict_types=1);

/**
 * GET /api/assets/generate_pwa_icon
 * Generate a branded PWA icon with tenant brand_color
 *
 * Query: ?tenant_id=1&size=192
 */

$tenantId = (int) ($_GET['tenant_id'] ?? 0);
$size     = (int) ($_GET['size'] ?? 192);

if ($tenantId <= 0) {
    // No tenant context (e.g. superadmin session) — generate default REGULR icon
    generateBrandedIcon([
        'name'            => 'REGULR',
        'brand_color'     => '#FFC107',
        'secondary_color' => '#FF9800',
    ], $size);
    exit;
}

// Validate size (common PWA icon sizes)
$allowedSizes = [72, 96, 128, 144, 152, 192, 384, 512];
if (!in_array($size, $allowedSizes, true)) {
    $size = 192;
}

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById($tenantId);

if ($tenant === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Tenant niet gevonden']);
    exit;
}

// Check if GD library is available
if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
    // Fallback: redirect to a default icon or return a simple colored PNG
    generateFallbackIcon($tenant['brand_color'] ?? '#FFC107', $size);
    exit;
}

generateBrandedIcon($tenant, $size);
exit;

/**
 * Generate a branded PWA icon using GD library
 */
function generateBrandedIcon(array $tenant, int $size): void
{
    $brandColor    = $tenant['brand_color'] ?? '#FFC107';
    $secondaryColor = $tenant['secondary_color'] ?? '#FF9800';
    $name          = $tenant['name'] ?? 'S';

    // Create image
    $img = imagecreatetruecolor($size, $size);
    if ($img === false) {
        generateFallbackIcon($brandColor, $size);
        return;
    }

    // Enable alpha blending
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Parse brand color
    $brandRgb = hexToRgb($brandColor);
    $secondaryRgb = hexToRgb($secondaryColor);

    // Fill with gradient (top-left to bottom-right)
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $ratio = ($x + $y) / ($size * 2);
            $r = (int) ($brandRgb['r'] * (1 - $ratio) + $secondaryRgb['r'] * $ratio);
            $g = (int) ($brandRgb['g'] * (1 - $ratio) + $secondaryRgb['g'] * $ratio);
            $b = (int) ($brandRgb['b'] * (1 - $ratio) + $secondaryRgb['b'] * $ratio);
            $color = imagecolorallocate($img, $r, $g, $b);
            if ($color !== false) {
                imagesetpixel($img, $x, $y, $color);
            }
        }
    }

    // Draw rounded rectangle overlay (subtle border effect)
    $borderColor = imagecolorallocate($img, 255, 255, 255);
    if ($borderColor !== false) {
        $margin = max(2, (int) ($size * 0.04));
        imagerectangle($img, $margin, $margin, $size - $margin - 1, $size - $margin - 1, $borderColor);
    }

    // Draw first letter of establishment name centered
    $letter = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    $fontSize = (int) ($size * 0.5);

    // Use built-in GD font (largest is 5, ~15px). For better results, use imagettftext with a font file.
    if (function_exists('imagettftext')) {
        // Try to use a system font
        $fontPaths = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];

        $fontPath = null;
        foreach ($fontPaths as $fp) {
            if (file_exists($fp)) {
                $fontPath = $fp;
                break;
            }
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        if ($fontPath !== null && $white !== false) {
            $textBox = imagettfbbox($fontSize, 0, $fontPath, $letter);
            if ($textBox !== false) {
                $textWidth  = $textBox[2] - $textBox[0];
                $textHeight = $textBox[1] - $textBox[7];
                $x = (int) (($size - $textWidth) / 2);
                $y = (int) (($size + $textHeight) / 2);
                imagettftext($img, $fontSize, 0, $x, $y, $white, $fontPath, $letter);
            }
        } else {
            // Fallback: no font available, draw a circle
            drawCircleIcon($img, $size, $white);
        }
    } else {
        // No FreeType — draw a simple circle instead
        $white = imagecolorallocate($img, 255, 255, 255);
        drawCircleIcon($img, $size, $white);
    }

    // Output PNG
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400'); // Cache 24h
    imagepng($img);
    imagedestroy($img);
}

/**
 * Draw a centered circle when no font is available
 */
function drawCircleIcon($img, int $size, $whiteColor): void
{
    if ($whiteColor === false) {
        return;
    }
    $center = (int) ($size / 2);
    $radius = (int) ($size * 0.3);
    imagefilledellipse($img, $center, $center, $radius * 2, $radius * 2, $whiteColor);
}

/**
 * Fallback: generate a simple 1x1 PNG with brand color
 */
function generateFallbackIcon(string $brandColor, int $size): void
{
    $rgb = hexToRgb($brandColor);
    $img = imagecreatetruecolor($size, $size);
    if ($img === false) {
        // Last resort: empty PNG
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        return;
    }

    $color = imagecolorallocate($img, $rgb['r'], $rgb['g'], $rgb['b']);
    if ($color !== false) {
        imagefill($img, 0, 0, $color);
    }

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    imagepng($img);
    imagedestroy($img);
}

/**
 * Convert hex color to RGB array
 *
 * @param string $hex Hex color string (#RRGGBB)
 * @return array{r: int, g: int, b: int}
 */
function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return ['r' => 255, 'g' => 193, 'b' => 7]; // Default gold
    }
    return [
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2)),
    ];
}
