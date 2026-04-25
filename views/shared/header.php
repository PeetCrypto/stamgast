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
$tenantLogo = $_SESSION['tenant_logo'] ?? ''; // Tenant uploaded logo URL
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
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/favicon.png">

    <!-- PWA Manifest -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json.php">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- App Base Path (for JS API calls) -->
    <script>window.__BASE_URL = '<?= defined("BASE_URL") ? BASE_URL : "" ?>';</script>

    <!-- Midnight Lounge Design System -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/midnight-lounge.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/views.css">

    <!-- Tenant-specific CSS variable overrides -->
    <style>
        :root {
            --accent-primary: <?= sanitize($brandColor) ?>;
            --accent-secondary: <?= sanitize($secondaryColor) ?>;
            --accent-gradient: linear-gradient(135deg, <?= sanitize($brandColor) ?> 0%, <?= sanitize($secondaryColor) ?> 100%);
        }
    </style>
</head>
<body class="<?= isset($bodyClass) ? sanitize($bodyClass) : '' ?>">

<?php if (isLoggedIn()): ?>
<nav class="nav-top">
    <div style="display:flex;align-items:center;width:100%;">
        <!-- Left: REGULR.vip platform branding -->
        <a href="<?= BASE_URL ?>" style="display:flex;align-items:center;gap:6px;text-decoration:none;flex:1;">
            <img src="<?= BASE_URL ?>/icons/regulr-vip-logo.png" alt="REGULR.vip" style="height:28px;width:auto;border-radius:4px;background:transparent;">
            <span style="font-weight:700;color:#FFC107;font-size:14px;">REGULR.vip</span>
        </a>

        <?php if ($userRole === 'superadmin'): ?>
        <!-- Center: No tenant logo for superadmin (multi-tenant platform level) -->
        <?php elseif ($userRole === 'admin'): ?>
        <!-- Center: Tenant logo (admin) -->
        <a href="<?= BASE_URL ?>/admin" style="display:flex;align-items:center;justify-content:center;flex:0 0 auto;">
            <?php if (!empty($tenantLogo)): ?>
            <img src="<?= sanitize($tenantLogo) ?>" alt="<?= sanitize($tenantName) ?>" style="height:32px;width:auto;max-width:140px;object-fit:contain;">
            <?php else: ?>
            <span style="font-weight:600;color:var(--text-primary);"><?= sanitize($tenantName) ?></span>
            <?php endif; ?>
        </a>
        <?php else: ?>
        <!-- Center: Tenant logo (bartender/guest) -->
        <a href="<?= BASE_URL ?>/dashboard" style="display:flex;align-items:center;justify-content:center;flex:0 0 auto;">
            <?php if (!empty($tenantLogo)): ?>
            <img src="<?= sanitize($tenantLogo) ?>" alt="<?= sanitize($tenantName) ?>" style="height:36px;width:auto;max-width:150px;object-fit:contain;">
            <?php else: ?>
            <span style="font-weight:600;color:var(--text-primary);"><?= sanitize($tenantName) ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <!-- Right: User greeting -->
        <span style="flex:1;display:flex;align-items:center;justify-content:flex-end;color:rgba(255,255,255,0.6);font-size:14px;">
            Hoi, <?= sanitize($userName) ?>
        </span>
    </div>
</nav>
<?php endif; ?>
