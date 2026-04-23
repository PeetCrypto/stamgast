<?php
declare(strict_types=1);

/**
 * STAMGAST - Entry Point / Router
 * Routes all requests to the correct API endpoint or View template
 */

// --- Load Configuration ---
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/audit.php';
require_once __DIR__ . '/utils/validator.php';
require_once __DIR__ . '/middleware/auth_check.php';

// --- Autoload Models ---
spl_autoload_register(function (string $class) {
    $modelPath = __DIR__ . '/models/' . $class . '.php';
    if (file_exists($modelPath)) {
        require_once $modelPath;
    }
});

// --- Start Session ---
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly'  => true,
        'secure'    => (!APP_DEBUG), // Only secure in production
        'samesite'  => 'Strict',
        'lifetime'  => SESSION_TIMEOUT,
    ]);
    session_start();
}

// --- Get Route ---
$route = trim($_GET['route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

// --- Run Auth Check (session timeout etc.) ---
// Skip auth check for API requests (they have their own auth middleware)
if ($route !== '' && !str_starts_with($route, 'api/')) {
    authCheck();
}

// --- CORS for API requests ---
$apiRouteCheck = $route;
if (str_starts_with($apiRouteCheck, 'stamgast/')) {
    $apiRouteCheck = substr($apiRouteCheck, 9);
}
if (str_starts_with($apiRouteCheck, 'api/')) {
    setCORSHeaders();
}

// ==========================================================================
// ROUTING
// ==========================================================================

// --- PWA Manifest (PHP-generated, must be required not readfile'd) ---
$manifestRoute = $route;
if (str_starts_with($manifestRoute, 'stamgast/')) {
    $manifestRoute = substr($manifestRoute, 9);
}
if ($manifestRoute === 'manifest.json.php') {
    require PUBLIC_PATH . 'manifest.json.php';
    exit;
}

// --- Static Files (CSS, JS, images, favicon) ---
// Remove /stamgast prefix from route for static files
$staticRoute = $route;
if (str_starts_with($staticRoute, 'stamgast/')) {
    $staticRoute = substr($staticRoute, 9);
}
if (str_starts_with($staticRoute, 'css/') || str_starts_with($staticRoute, 'js/') || str_starts_with($staticRoute, 'icons/') || str_starts_with($staticRoute, 'uploads/') || $staticRoute === 'favicon.ico') {
    $filePath = PUBLIC_PATH . $staticRoute;
    if (file_exists($filePath)) {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'ico' => 'image/x-icon',
            'json' => 'application/json',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'text/plain'));
        readfile($filePath);
        exit;
    }
}

// --- API Routes ---
// Remove /stamgast prefix if present
$apiRouteCheck = $route;
if (str_starts_with($apiRouteCheck, 'stamgast/')) {
    $apiRouteCheck = substr($apiRouteCheck, 9);
}
if (str_starts_with($apiRouteCheck, 'api/')) {
    // Convert PHP warnings/notices to exceptions so they don't leak HTML into JSON responses
    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    try {
        ob_start(); // Buffer any accidental output (BOM, whitespace, warnings)
        // Remove 'stamgast/' and 'api/' prefixes
        $cleanRoute = $route;
        if (str_starts_with($cleanRoute, 'stamgast/')) {
            $cleanRoute = substr($cleanRoute, 9);
        }
        $apiRoute = substr($cleanRoute, 4); // Remove 'api/'
        handleApiRoute($apiRoute, $method);
    } catch (\Throwable $e) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => APP_DEBUG ? $e->getMessage() : 'Internal server error',
            'code'    => 'INTERNAL_ERROR',
            'debug'   => APP_DEBUG ? [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'trace' => array_slice(array_map(function ($f) {
                    return ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . ' @ ' . basename($f['file'] ?? '') . ':' . ($f['line'] ?? '');
                }, $e->getTrace()), 0, 8),
            ] : null,
        ]);
    }
    exit;
}

// --- View Routes ---
handleViewRoute($route, $method);
exit;

// ==========================================================================
// API ROUTER
// ==========================================================================
function handleApiRoute(string $route, string $method): void
{
    $segments = explode('/', $route);
    $group = $segments[0] ?? '';
    $action = $segments[1] ?? '';

    // Require CSRF check for state-changing requests
    // Skip CSRF for: auth (public endpoints), upload (multipart/form-data sends token as form field)
    if (!in_array($group, ['auth', 'upload'], true)) {
        require_once __DIR__ . '/middleware/csrf.php';
        csrfCheck();
    }

    switch ($group) {
        // --- AUTH ---
        case 'auth':
            require_once __DIR__ . '/middleware/auth_check.php';
            switch ($action) {
                case 'login':
                    require __DIR__ . '/api/auth/login.php';
                    break;
                case 'register':
                    require __DIR__ . '/api/auth/register.php';
                    break;
                case 'logout':
                    require __DIR__ . '/api/auth/logout.php';
                    break;
                case 'session':
                    require __DIR__ . '/api/auth/session.php';
                    break;
                default:
                    Response::notFound('Auth endpoint not found');
            }
            break;

        // --- WALLET ---
        case 'wallet':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAuthenticated();
            switch ($action) {
                case 'balance':
                    require __DIR__ . '/api/wallet/balance.php';
                    break;
                case 'deposit':
                    require __DIR__ . '/api/wallet/deposit.php';
                    break;
                case 'history':
                    require __DIR__ . '/api/wallet/history.php';
                    break;
                default:
                    Response::notFound('Wallet endpoint not found');
            }
            break;

        // --- POS (Bartender) ---
        case 'pos':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireBartender();
            // IP whitelist enforcement (skip in dev if no IPs configured)
            require_once __DIR__ . '/middleware/ip_whitelist.php';
            if (!empty($_SESSION['whitelisted_ips'])) {
                enforceIPWhitelist(explode("\n", $_SESSION['whitelisted_ips']));
            }
            switch ($action) {
                case 'scan':
                    require __DIR__ . '/api/pos/scan.php';
                    break;
                case 'process_payment':
                    require __DIR__ . '/api/pos/process_payment.php';
                    break;
                default:
                    Response::notFound('POS endpoint not found');
            }
            break;

        // --- QR ---
        case 'qr':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAuthenticated();
            switch ($action) {
                case 'generate':
                    require __DIR__ . '/api/qr/generate.php';
                    break;
                default:
                    Response::notFound('QR endpoint not found');
            }
            break;

        // --- ADMIN ---
        case 'admin':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAdmin();
            switch ($action) {
                case 'dashboard':
                    require __DIR__ . '/api/admin/dashboard.php';
                    break;
                case 'users':
                    require __DIR__ . '/api/admin/users.php';
                    break;
                case 'tiers':
                    require __DIR__ . '/api/admin/tiers.php';
                    break;
                case 'settings':
                    require __DIR__ . '/api/admin/settings.php';
                    break;
                default:
                    Response::notFound('Admin endpoint not found');
            }
            break;

        // --- SUPERADMIN ---
        case 'superadmin':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireSuperAdmin();
            switch ($action) {
                case 'tenants':
                    require __DIR__ . '/api/superadmin/tenants.php';
                    break;
                case 'overview':
                    require __DIR__ . '/api/superadmin/overview.php';
                    break;
                default:
                    Response::notFound('Super-admin endpoint not found');
            }
            break;

        // --- PUSH ---
        case 'push':
            require_once __DIR__ . '/middleware/auth_check.php';
            switch ($action) {
                case 'subscribe':
                    require __DIR__ . '/api/push/subscribe.php';
                    break;
                case 'send_notification':
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAdmin();
                    require __DIR__ . '/api/push/send_notification.php';
                    break;
                default:
                    Response::notFound('Push endpoint not found');
            }
            break;

        // --- MARKETING ---
        case 'marketing':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAdmin();
            switch ($action) {
                case 'segment':
                    require __DIR__ . '/api/marketing/segment.php';
                    break;
                case 'compose':
                    require __DIR__ . '/api/marketing/compose.php';
                    break;
                case 'queue':
                    require __DIR__ . '/api/marketing/queue.php';
                    break;
                default:
                    Response::notFound('Marketing endpoint not found');
            }
            break;

        // --- MOLLIE WEBHOOK (no auth) ---
        case 'mollie':
            if ($action === 'webhook') {
                require __DIR__ . '/api/mollie/webhook.php';
            } else {
                Response::notFound('Mollie endpoint not found');
            }
            break;

        // --- UPLOAD ---
        case 'upload':
            // Direct session check - skip auth middleware (which includes CSRF check)
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $tenantId = currentTenantId();
            if (!$tenantId) {
                Response::error('Niet ingelogd', 'NO_SESSION', 401);
            }
            switch ($action) {
                case 'logo':
                    require __DIR__ . '/api/upload/logo.php';
                    break;
                default:
                    Response::notFound('Upload endpoint not found');
            }
            break;

        // --- ASSETS ---
        case 'assets':
            switch ($action) {
                case 'generate_pwa_icon':
                    require __DIR__ . '/api/assets/generate_pwa_icon.php';
                    break;
                default:
                    Response::notFound('Asset endpoint not found');
            }
            break;

        default:
            Response::notFound('API group not found');
    }
}

// ==========================================================================
// VIEW ROUTER
// ==========================================================================
function handleViewRoute(string $route, string $method): void
{
    // Allow direct file access for setup.php and test scripts
    if ($route === 'setup.php' || $route === 'test_phase1_phase2.php' || $route === 'db.php') {
        $filePath = __DIR__ . '/' . $route;
        if (file_exists($filePath)) {
            require $filePath;
            exit;
        }
    }
    
    // Redirect root based on session
    if ($route === '' || $route === '/') {
        if (isLoggedIn()) {
            $role = currentUserRole();
            $dashboardMap = [
                'superadmin' => '/superadmin',
                'admin'      => '/admin',
                'bartender'  => '/scan',
                'guest'      => '/dashboard',
            ];
            redirect($dashboardMap[$role] ?? '/login');
        }
        redirect('/login');
    }

    $role = currentUserRole() ?? 'anonymous';

    // --- Map routes to view files ---
    $viewMap = [
        // Shared (no auth required)
        'login'    => 'shared/login.php',
        'register' => 'shared/register.php',

        // Guest
        'dashboard' => 'guest/dashboard.php',
        'wallet'    => 'guest/wallet.php',
        'qr'        => 'guest/qr.php',
        'inbox'     => 'guest/inbox.php',

        // Bartender
        'bartender' => 'bartender/dashboard.php',
        'scan'    => 'bartender/scanner.php',
        'payment' => 'bartender/payment.php',

        // Admin
        'admin'             => 'admin/dashboard.php',
        'admin/users'       => 'admin/users.php',
        'admin/tiers'       => 'admin/tiers.php',
        'admin/settings'    => 'admin/settings.php',
        'admin/marketing'   => 'admin/marketing.php',

        // Super-admin
        'superadmin'            => 'superadmin/dashboard.php',
        'superadmin/tenants'    => 'superadmin/tenants.php',
        'superadmin/tenant'     => 'superadmin/tenant_detail.php',
    ];

    // Handle logout (both GET and POST destroy the session)
    if ($route === 'logout') {
        session_unset();
        session_destroy();
        redirect('/login');
    }

    // Find view file
    $viewFile = $viewMap[$route] ?? null;

    // Handle dynamic routes (e.g., superadmin/tenant/1)
    if ($viewFile === null && str_starts_with($route, 'superadmin/tenant/')) {
        $viewFile = 'superadmin/tenant_detail.php';
    }

    if ($viewFile === null) {
        http_response_code(404);
        require VIEWS_PATH . 'shared/header.php';
        echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Pagina niet gevonden</p><a href="/" class="btn btn-primary">Terug naar home</a></div>';
        require VIEWS_PATH . 'shared/footer.php';
        exit;
    }

    // Enforce auth for protected views
    $publicViews = ['shared/login.php', 'shared/register.php'];
    if (!in_array($viewFile, $publicViews) && !isLoggedIn()) {
        redirect('/login');
    }

    // Enforce role-based access
    $roleViews = [
        'superadmin' => ['superadmin/dashboard.php', 'superadmin/tenants.php', 'superadmin/tenant_detail.php'],
        'admin'      => ['admin/dashboard.php', 'admin/users.php', 'admin/tiers.php', 'admin/settings.php', 'admin/marketing.php'],
        'bartender'  => ['bartender/scanner.php', 'bartender/payment.php'],
    ];

    foreach ($roleViews as $requiredRole => $views) {
        if (in_array($viewFile, $views) && $role !== $requiredRole && $role !== 'superadmin') {
            // If admin tries to access superadmin views, deny
            if ($requiredRole === 'superadmin' && $role !== 'superadmin') {
                redirect('/dashboard');
            }
        }
    }

    // Render the view
    $viewPath = VIEWS_PATH . $viewFile;
    if (file_exists($viewPath)) {
        require $viewPath;
    } else {
        http_response_code(404);
        echo '<h1>View not found: ' . sanitize($viewFile) . '</h1>';
    }
}
