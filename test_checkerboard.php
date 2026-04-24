<?php
/**
 * Quick test: run the fixed cleanCheckerboardTransparency on current logo
 */

$logoFile = 'D:/laragon/www/stamgast/public/uploads/logos/tenant_1_1776966468.png';
echo "File: $logoFile\n";
echo "Size before: " . filesize($logoFile) . " bytes\n";

function getPixelRGBA($img, int $x, int $y): array
{
    $colorIndex = imagecolorat($img, $x, $y);
    $rgba = imagecolorsforindex($img, $colorIndex);
    $standardAlpha = (int)round((127 - $rgba['alpha']) / 127 * 255);
    return ['r' => $rgba['red'], 'g' => $rgba['green'], 'b' => $rgba['blue'], 'a' => $standardAlpha];
}

function colorDistance(array $pixel, array $reference): float
{
    return sqrt(pow($pixel['r'] - $reference['r'], 2) + pow($pixel['g'] - $reference['g'], 2) + pow($pixel['b'] - $reference['b'], 2));
}

function cleanCheckerboardTransparency(string $filePath): bool
{
    if (!function_exists('imagecreatefrompng')) return false;
    $img = @imagecreatefrompng($filePath);
    if (!$img) return false;

    $width = imagesx($img);
    $height = imagesy($img);
    if ($width < 20 || $height < 20) { imagedestroy($img); return false; }

    imagesavealpha($img, true);
    imagealphablending($img, false);

    $edgePixels = [];
    $sampleMargin = min(50, (int)($width * 0.1), (int)($height * 0.1));
    for ($x = 0; $x < $sampleMargin; $x++) {
        $edgePixels[] = getPixelRGBA($img, $x, 0);
        $edgePixels[] = getPixelRGBA($img, $x, $height - 1);
    }
    for ($y = 0; $y < $sampleMargin; $y++) {
        $edgePixels[] = getPixelRGBA($img, 0, $y);
        $edgePixels[] = getPixelRGBA($img, $width - 1, $y);
    }

    $opaqueCount = 0;
    $transparentCount = 0;
    foreach ($edgePixels as $px) {
        if ($px['a'] > 200) $opaqueCount++;
        elseif ($px['a'] < 50) $transparentCount++;
    }
    echo "Edge pixels: opaque=$opaqueCount, transparent=$transparentCount\n";

    if ($transparentCount > $opaqueCount) { imagedestroy($img); return false; }

    $colorClusters = [];
    foreach ($edgePixels as $px) {
        if ($px['a'] < 200) continue;
        $qr = (int)(round($px['r'] / 8) * 8);
        $qg = (int)(round($px['g'] / 8) * 8);
        $qb = (int)(round($px['b'] / 8) * 8);
        $key = "{$qr},{$qg},{$qb}";
        if (!isset($colorClusters[$key])) $colorClusters[$key] = ['count' => 0, 'r' => $qr, 'g' => $qg, 'b' => $qb];
        $colorClusters[$key]['count']++;
    }

    uasort($colorClusters, fn($a, $b) => $b['count'] <=> $a['count']);
    $topColorsArr = array_values(array_slice($colorClusters, 0, 5));

    echo "Top colors:\n";
    foreach ($topColorsArr as $i => $c) {
        $avg = ($c['r'] + $c['g'] + $c['b']) / 3;
        echo "  #" . ($i+1) . ": RGB({$c['r']},{$c['g']},{$c['b']}) avg=" . round($avg) . " count={$c['count']}\n";
    }

    if (count($topColorsArr) < 2) { imagedestroy($img); return false; }

    $c1 = $topColorsArr[0];
    $c2 = $topColorsArr[1];
    $avg1 = ($c1['r'] + $c1['g'] + $c1['b']) / 3;
    $avg2 = ($c2['r'] + $c2['g'] + $c2['b']) / 3;

    if ($avg1 < 180 || $avg2 < 180) { imagedestroy($img); echo "FAIL: colors not light\n"; return false; }

    $colorDiff = abs($avg1 - $avg2);
    echo "Color diff: $colorDiff\n";
    if ($colorDiff < 5 || $colorDiff > 80) { imagedestroy($img); echo "FAIL: color diff out of range\n"; return false; }

    $tolerance = 30;
    $pixelsChanged = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $px = getPixelRGBA($img, $x, $y);
            if ($px['a'] < 200) continue;
            $dist1 = colorDistance($px, $c1);
            $dist2 = colorDistance($px, $c2);
            if ($dist1 <= $tolerance || $dist2 <= $tolerance) {
                $newColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
                imagesetpixel($img, $x, $y, $newColor);
                $pixelsChanged++;
            }
        }
    }

    $totalPixels = $width * $height;
    $changeRatio = $pixelsChanged / $totalPixels;
    echo "Pixels changed: $pixelsChanged / $totalPixels (" . round($changeRatio*100, 1) . "%)\n";

    if ($changeRatio < 0.05) { imagedestroy($img); echo "FAIL: too few pixels\n"; return false; }

    imagepng($img, $filePath, 9);
    imagedestroy($img);
    echo "SUCCESS!\n";
    return true;
}

$result = cleanCheckerboardTransparency($logoFile);
echo "Size after: " . filesize($logoFile) . " bytes\n";
echo "Result: " . ($result ? "CLEANED" : "NOT CLEANED") . "\n";
