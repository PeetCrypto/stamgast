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