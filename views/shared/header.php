<?php
declare(strict_types=1);
/**
 * Shared Header Template
 * Midnight Lounge Design System - links to external CSS files
 * Tenant-specific CSS variables are injected inline for dynamic branding
 */
$csrfToken = generateCSRFToken();
$userRole  = currentUserRole() ?? 'anonymous';
$userName  = $_SESSION['first_name'] ?? 'Gast';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$brandColor = $_SESSION['brand_color'] ?? '#FFC107';
$secondaryColor = $_SESSION['secondary_color'] ?? '#FF9800';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="description" content="<?= sanitize($tenantName) ?> - Loyalty platform">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= sanitize($tenantName) ?> - STAMGAST</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon.png">
    <link rel="apple-touch-icon" href="/icons/favicon.png">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json.php">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Midnight Lounge Design System -->
    <link rel="stylesheet" href="/css/midnight-lounge.css">
    <link rel="stylesheet" href="/css/components.css">
    <link rel="stylesheet" href="/css/views.css">

    <!-- Tenant-specific CSS variable overrides -->
    <style>
        :root {
            --accent-primary: <?= sanitize($brandColor) ?>;
            --accent-secondary: <?= sanitize($secondaryColor) ?>;
            --accent-gradient: linear-gradient(135deg, <?= sanitize($brandColor) ?> 0%, <?= sanitize($secondaryColor) ?> 100%);
        }
    </style>
</head>
<body>

<?php if (isLoggedIn()): ?>
<nav class="nav-top">
    <a href="/" class="nav-brand" style="display:flex;align-items:center;gap:8px;text-decoration:none;">
        <?php if ($userRole === 'superadmin' || $userRole === 'admin'): ?>
            <img src="/icons/regulr-vip-logo.png" alt="REGULR.vip" style="height:32px;width:auto;border-radius:6px;">
            <span style="font-weight:700;color:#FFC107;">REGULR.vip</span>
        <?php else: ?>
            <?= sanitize($tenantName) ?>
        <?php endif; ?>
    </a>
    <span class="nav-user">Hoi, <?= sanitize($userName) ?></span>
</nav>
<?php endif; ?>
