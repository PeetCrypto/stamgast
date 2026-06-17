<?php
declare(strict_types=1);

/**
 * REGULR.vip - Entry Point / Router
 * Routes all requests to the correct API endpoint or View template
 */

// --- Load Configuration ---
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/utils/Crypto.php';
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
    // Bepaal cookie lifetime op basis van rol
    // Bij nieuwe login is role nog niet bekend → korte lifetime (30 min)
    // Na login wordt cookie vernieuwd met juiste lifetime (zie keepalive endpoint)
    $cookieLifetime = SESSION_TIMEOUT; // default 30 min (staff)

    // Als session cookie bestaat: probeer rol te bepalen voor juiste lifetime
    // Dit werkt omdat PHP de session data pas NA session_start() leest,
    // maar we kunnen de session ID gebruiken om de session file te openen.
    // Simpelere aanpak: start met korte lifetime, keepalive/login vernieuwt later.
    if (isset($_COOKIE[session_name()])) {
        // Bestaande session — start met session om rol te checken
        session_set_cookie_params([
            'httponly'  => true,
            'secure'    => (!APP_DEBUG),
            'samesite'  => 'Lax',
            'lifetime'  => $cookieLifetime,
        ]);
        session_start();

        // Nu we session data hebben: check rol en vernieuw cookie indien gast
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') {
            // SECURITY: Periodically regenerate session ID to limit the window of a stolen session.
            // Without this, a leaked session ID remains valid for the full 60-day guest cookie lifetime.
            $lastRegen = $_SESSION['last_session_regen'] ?? 0;
            if (time() - (int) $lastRegen > 86400) { // 24 hours
                session_regenerate_id(true);
                $_SESSION['last_session_regen'] = time();
            }

            // SECURITY: Use array notation to preserve the SameSite=Lax flag.
            // The old positional setcookie() call dropped SameSite, falling back to
            // browser default (None in older browsers), making CSRF easier.
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + SESSION_COOKIE_LIFETIME_GUEST,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    } else {
        // Nieuwe session (geen cookie)
        session_set_cookie_params([
            'httponly'  => true,
            'secure'    => (!APP_DEBUG),
            'samesite'  => 'Lax',
            'lifetime'  => $cookieLifetime,
        ]);
        session_start();
    }
}

// --- Timezone Initialisation (per request, AFTER session_start) ---
// Sets PHP date_default_timezone_set() + MySQL SET time_zone based on tenant.
// Falls back to 'Europe/Amsterdam' if no tenant context available.
// MUST be after session_start() because it reads $_SESSION['tenant_id'].
require_once __DIR__ . '/services/TimezoneService.php';
$db = Database::getInstance()->getConnection();
$tzTenantId = $_SESSION['tenant_id'] ?? null;
TimezoneService::init($db, $tzTenantId);

// --- Load Emergency Token Hash from DB ---
// Break-glass superadmin access token. Loaded from platform_settings
// so it can be invalidated after use without redeploying.
try {
    $emergencyStmt = $db->prepare("SELECT `setting_value` FROM `platform_settings` WHERE `setting_key` = 'emergency_token_hash' AND `setting_value` != '' LIMIT 1");
    $emergencyStmt->execute();
    $emergencyHash = $emergencyStmt->fetchColumn();
    if (!empty($emergencyHash)) {
        putenv('EMERGENCY_TOKEN_HASH=' . $emergencyHash);
        $_ENV['EMERGENCY_TOKEN_HASH'] = $emergencyHash;
    }
} catch (\Throwable $e) {
    // platform_settings table may not exist yet — non-fatal
}

// --- Get Route ---
$route = trim($_GET['route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

// Handle subdirectory logic (e.g. localhost/stamgast/api/...)
if (str_starts_with($route, 'stamgast/')) {
    $route = substr($route, 9);
}

// --- Run Auth Check (session timeout etc.) ---
// Skip auth check for API requests (they have their own auth middleware)
if ($route !== '' && !str_starts_with($route, 'api/') && $route !== 'push-test' && $route !== 'sw.js' && $route !== 'migrate') {
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
if ($route === 'manifest.json.php') {
    require PUBLIC_PATH . 'manifest.json.php';
    exit;
}

// Served via PHP instead of .htaccess rewrite — works on Hostinger shared hosting
if ($route === 'sw.js' || $route === 'firebase-messaging-sw.js' || $route === 'fcm-sw.js') {
    $swPath = PUBLIC_PATH . 'js/' . $route;
    if (file_exists($swPath)) {
        header('Content-Type: application/javascript');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        $swContent = file_get_contents($swPath);
        
        // Inject Firebase config from constants
        $swContent = str_replace(
            [
                '__FIREBASE_API_KEY__',
                '__FIREBASE_PROJECT_ID__',
                '__FIREBASE_MESSAGING_SENDER_ID__',
                '__FIREBASE_APP_ID__'
            ],
            [
                FIREBASE_API_KEY,
                FIREBASE_PROJECT_ID,
                FIREBASE_MESSAGING_SENDER_ID,
                FIREBASE_APP_ID
            ],
            $swContent
        );
        
        echo $swContent;
        exit;
    }
}

// Minimal test SW — no caching, no fetch handler, just push event
if ($route === 'test-sw.js') {
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    echo "self.addEventListener('push', e => { const d = e.data ? e.data.json() : {}; e.waitUntil(self.registration.showNotification(d.title || 'test', { body: d.body || '' })); }); self.addEventListener('install', () => self.skipWaiting()); self.addEventListener('activate', () => self.clients.claim());";
    exit;
}

// --- FCM Test Page (temporary diagnostic tool) ---
if ($route === 'fcm-test') {
    require __DIR__ . '/fcm-test.php';
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
    // Fallback: serve PNG favicon if ICO file is missing (e.g. not deployed to production)
    if ($staticRoute === 'favicon.ico' && !file_exists($filePath)) {
        $filePath = PUBLIC_PATH . 'icons/favicon.png';
    }

    // SECURITY: Validate that the resolved path is inside PUBLIC_PATH.
    // Prevents path traversal (e.g. css/../../../config/app.php) on servers
    // that don't normalize ../ in URLs.
    $realFilePath = realpath($filePath);
    $realPublicPath = realpath(PUBLIC_PATH);
    if ($realFilePath === false || $realPublicPath === false || !str_starts_with($realFilePath, $realPublicPath)) {
        http_response_code(403);
        exit;
    }

    if (file_exists($realFilePath)) {
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
        readfile($realFilePath);
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
        // SECURITY: Never leak internal error details in HTTP responses, even for superadmins.
        // Full error details go to server logs only. A stolen superadmin session should not
        // reveal file paths, line numbers, or stack traces that aid further exploitation.
        error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $showDebug = APP_DEBUG; // Only in development mode
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $showDebug ? $e->getMessage() : 'Internal server error',
            'code'    => 'INTERNAL_ERROR',
            'debug'   => $showDebug ? [
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

// --- Join Route: /j/{slug} and /j/{slug}/register — Guest auth (no prior auth required) ---
$joinRoute = $route;
if (str_starts_with($joinRoute, 'stamgast/')) {
    $joinRoute = substr($joinRoute, 9);
}
if (str_starts_with($joinRoute, 'j/')) {
    $slugPart = substr($joinRoute, 2);

    // Determine if this is /j/{slug}/register, /j/{slug}/verify, /j/{slug}/forgot-password, /j/{slug}/reset-password, or just /j/{slug}
    $isRegister = false;
    $isVerify = false;
    $isForgotPassword = false;
    $isResetPassword = false;
    $slug = $slugPart;

    if (str_ends_with($slugPart, '/register')) {
        $isRegister = true;
        $slug = substr($slugPart, 0, -9);
    } elseif (str_ends_with($slugPart, '/verify')) {
        $isVerify = true;
        $slug = substr($slugPart, 0, -7);
    } elseif (str_ends_with($slugPart, '/forgot-password')) {
        $isForgotPassword = true;
        $slug = substr($slugPart, 0, -16);
    } elseif (str_ends_with($slugPart, '/reset-password')) {
        $isResetPassword = true;
        $slug = substr($slugPart, 0, -15);
    }

    // Security: only lowercase alphanumeric + hyphens
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,98}[a-z0-9]$/', $slug)) {
        http_response_code(404);
        echo '<h1>404 — Ongeldige link</h1>';
        exit;
    }

    require_once __DIR__ . '/models/Tenant.php';
    $db = Database::getInstance()->getConnection();
    $tenant = (new Tenant($db))->findBySlug($slug);

    if (!$tenant || !(bool) $tenant['is_active']) {
        http_response_code(404);
        echo '<h1>404 — Locatie niet gevonden</h1><p>Deze link bestaat niet of is niet meer actief.</p>';
        exit;
    }

    // Already logged in at this tenant → go to dashboard
    // EXCEPTION: skip redirect for /verify route (email verification page)
    // to avoid redirect loop when dashboard redirects unverified users to /verify
    if (!$isVerify && isLoggedIn() && currentTenantId() === (int) $tenant['id']) {
        redirect('/dashboard');
    }

    // /j/{slug}/register        → registration page (join.php)
    // /j/{slug}/verify           → email verification page (verify-email.php)
    // /j/{slug}/forgot-password  → forgot password page
    // /j/{slug}/reset-password   → reset password page
    // /j/{slug}                  → login page (guest/login.php)
    if ($isRegister) {
        require VIEWS_PATH . 'guest/join.php';
    } elseif ($isVerify) {
        require VIEWS_PATH . 'guest/verify-email.php';
    } elseif ($isForgotPassword || $isResetPassword) {
        $viewFile = $isForgotPassword ? 'shared/forgot-password.php' : 'shared/reset-password.php';
        require VIEWS_PATH . $viewFile;
    } else {
        require VIEWS_PATH . 'guest/login.php';
    }
    exit;
}

// --- Database Migration (superadmin only) ---
if ($route === 'migrate') {
    if (isLoggedIn() && currentUserRole() === 'superadmin') {
        require ROOT_PATH . 'sql/web_migrate.php';
    } else {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
        echo '<h2 style="color:#f44336">403 — Geen toegang</h2>';
        echo '<p>Alleen de superadmin kan database migraties uitvoeren.</p>';
        echo '</body></html>';
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
    // Skip CSRF for: auth (public endpoints), upload (multipart/form-data sends token as form field),
    // mollie (webhook called by Mollie servers without CSRF token)
    if (!in_array($group, ['auth', 'upload', 'mollie'], true)) {
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
                case 'verify-email':
                    require __DIR__ . '/api/auth/verify-email.php';
                    break;
                case 'resend-verification':
                    require __DIR__ . '/api/auth/resend-verification.php';
                    break;
                case 'logout':
                    require __DIR__ . '/api/auth/logout.php';
                    break;
                case 'session':
                    require __DIR__ . '/api/auth/session.php';
                    break;
                case 'forgot-password':
                    require __DIR__ . '/api/auth/forgot-password.php';
                    break;
                case 'reset-password':
                    require __DIR__ . '/api/auth/reset-password.php';
                    break;
                case 'setup-password':
                    require __DIR__ . '/api/auth/setup-password.php';
                    break;
                case 'keepalive':
                    require __DIR__ . '/api/auth/keepalive.php';
                    break;
                case 'webauthn':
                    // WebAuthn sub-endpoints: register-options, register, authenticate-options, authenticate
                    $subAction = $segments[2] ?? '';
                    if (in_array($subAction, ['register-options', 'register', 'authenticate-options', 'authenticate'], true)) {
                        require_once __DIR__ . '/middleware/role_check.php';
                        requireAuthenticated();
                        if (currentUserRole() !== 'guest') {
                            Response::error('WebAuthn is only available for guests', 'FORBIDDEN', 403);
                        }
                        require __DIR__ . "/api/auth/webauthn/{$subAction}.php";
                    } else {
                        Response::notFound('WebAuthn endpoint not found');
                    }
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
                case 'packages':
                    require __DIR__ . '/api/wallet/packages.php';
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
                case 'verify':
                    require __DIR__ . '/api/pos/verify.php';
                    break;
                case 'create_session':
                    require __DIR__ . '/api/pos/create_session.php';
                    break;
                case 'session_status':
                    require __DIR__ . '/api/pos/session_status.php';
                    break;
                case 'qr_png':
                    require __DIR__ . '/api/pos/qr_png.php';
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

        // --- GUEST (payment flow: scan, confirm, cancel) ---
        case 'guest':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAuthenticated();
            switch ($action) {
                case 'scan_payment':
                    require __DIR__ . '/api/guest/scan_payment.php';
                    break;
                case 'confirm_payment':
                    require __DIR__ . '/api/guest/confirm_payment.php';
                    break;
                case 'cancel_payment':
                    require __DIR__ . '/api/guest/cancel_payment.php';
                    break;
                default:
                    Response::notFound('Guest endpoint not found');
            }
            break;

        // --- NOTIFICATION (Guest Inbox) ---
        case 'notification':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAuthenticated();
            switch ($action) {
                case 'check':
                    require __DIR__ . '/api/notification/check.php';
                    break;
                case 'delete':
                    require __DIR__ . '/api/notification/delete.php';
                    break;
                case 'mark_read':
                    require __DIR__ . '/api/notification/mark_read.php';
                    break;
                default:
                    Response::notFound('Notification endpoint not found');
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
                case 'suspend_user':
                    require __DIR__ . '/api/admin/suspend_user.php';
                    break;
                case 'cleanup':
                    require __DIR__ . '/api/admin/cleanup.php';
                    break;
                case 'push_history':
                    require __DIR__ . '/api/admin/push_history.php';
                    break;
                case 'reports':
                    require __DIR__ . '/api/admin/reports.php';
                    break;
                case 'connect-mollie':
                    require __DIR__ . '/api/admin/connect_mollie.php';
                    break;
                default:
            }
            break;

        // --- EMAIL ---
        case 'email':
            require_once __DIR__ . '/middleware/auth_check.php';
            require_once __DIR__ . '/middleware/role_check.php';
            requireAdmin();
            switch ($action) {
                case 'templates':
                    require __DIR__ . '/api/email/templates.php';
                    break;
                case 'config':
                    require __DIR__ . '/api/email/config.php';
                    break;
                default:
                    Response::notFound('Email endpoint not found');
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
                case 'fees':
                    require __DIR__ . '/api/superadmin/fees.php';
                    break;
                case 'invoices':
                    require __DIR__ . '/api/superadmin/invoices.php';
                    break;
                case 'settings':
                    require __DIR__ . '/api/superadmin/settings.php';
                    break;
                case 'admins':
                    require __DIR__ . '/api/superadmin/admins.php';
                    break;
                case 'connect-mollie':
                    require __DIR__ . '/api/superadmin/connect_mollie.php';
                    break;
                case 'view-as':
                    require __DIR__ . '/api/superadmin/view-as.php';
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
                case 'unsubscribe':
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAuthenticated();
                    require __DIR__ . '/api/push/unsubscribe.php';
                    break;
                case 'save-preference':
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAuthenticated();
                    require __DIR__ . '/api/push/save-preference.php';
                    break;
                case 'config':
                    require __DIR__ . '/api/push/config.php';
                    break;
                case 'send_notification':
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAdmin();
                    require __DIR__ . '/api/push/send_notification.php';
                    break;
                case 'broadcast':
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAdmin();
                    require __DIR__ . '/api/push/broadcast.php';
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
                case 'process':
                    require __DIR__ . '/api/marketing/process.php';
                    break;
                default:
                    Response::notFound('Marketing endpoint not found');
            }
            break;

        // --- MOLLIE WEBHOOK + CONNECT (no auth) ---
        case 'mollie':
            if ($action === 'webhook') {
                require __DIR__ . '/api/mollie/webhook.php';
            } elseif ($action === 'connect-callback') {
                require __DIR__ . '/api/mollie/connect-callback.php';
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
                case 'generate_join_qr':
                    require_once __DIR__ . '/middleware/auth_check.php';
                    authCheck();
                    require_once __DIR__ . '/middleware/role_check.php';
                    requireAdmin();
                    require __DIR__ . '/api/assets/generate_join_qr.php';
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
        'login'           => 'shared/login.php',
        'register'        => 'shared/register.php',
        'forgot-password' => 'shared/forgot-password.php',
        'reset-password'  => 'shared/reset-password.php',
        'setup-password'  => 'shared/setup-password.php',
        'push-test'       => 'shared/push-test.php',

        // Guest
        'dashboard' => 'guest/dashboard.php',
        'wallet'    => 'guest/wallet.php',
        'qr'        => 'guest/qr.php',
        'pay'       => 'guest/scan.php',
        'inbox'     => 'guest/inbox.php',
        'benefits'  => 'guest/benefits.php',
        'profile'   => 'guest/profile/index.php',
        'pin-setup' => 'guest/pin-setup.php',

        // Bartender
        'bartender' => 'bartender/dashboard.php',
        'scan'    => 'bartender/scanner.php',
        'payment' => 'bartender/payment.php',

        // Admin
        'admin'             => 'admin/dashboard.php',
        'admin/reports'     => 'admin/reports.php',
        'admin/users'       => 'admin/users.php',
        'admin/tiers'       => 'admin/tiers.php',
        'admin/settings'    => 'admin/settings.php',
        'admin/marketing'   => 'admin/marketing.php',
        'admin/push'        => 'admin/push.php',

// Super-admin
    'superadmin'                => 'superadmin/dashboard.php',
    'superadmin/tenants'        => 'superadmin/tenants.php',
    'superadmin/tenant'         => 'superadmin/tenant_detail.php',
    'superadmin/fees'             => 'superadmin/fees.php',
    'superadmin/invoices'         => 'superadmin/invoices.php',
    'superadmin/settings'         => 'superadmin/settings.php',
    'superadmin/migrate'          => 'superadmin/migrate.php',
    'superadmin/repair-deposits'  => 'superadmin/repair_deposits.php',
    'superadmin/emergency-token'  => 'superadmin/emergency_token.php',
    ];

    // Handle API routes first
    if (str_starts_with($route, 'api/')) {
        $apiFile = str_replace('/', '_', $route) . '.php';
        $apiPath = __DIR__ . '/' . $apiFile;
        if (file_exists($apiPath)) {
            require $apiPath;
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'API endpoint not found']);
            exit;
        }
    }

    // Handle logout (both GET and POST destroy the session)
    if ($route === 'logout') {
        // Determine redirect URL BEFORE destroying session
        $logoutRedirect = '/login';
        
        // 1. Check return URL from query param (explicit redirect)
        $returnUrl = $_GET['return'] ?? null;
        if ($returnUrl && preg_match('#^/j/[a-z0-9][a-z0-9-]{0,98}[a-z0-9](/|$)#', $returnUrl)) {
            $logoutRedirect = $returnUrl;
        }
        // 2. For tenant users (guest, admin, bartender): redirect to branded login /j/{slug}
        //    Only superadmins go to /login (no tenant context)
        elseif (isLoggedIn() && ($_SESSION['role'] ?? '') !== 'superadmin') {
            if (isset($_SESSION['tenant']['slug'])) {
                $logoutRedirect = '/j/' . $_SESSION['tenant']['slug'];
            } elseif (isset($_SESSION['tenant_id'])) {
                // Fallback: look up slug from database
                try {
                    $tenantModel = new Tenant($db);
                    $tenantData = $tenantModel->findById((int) $_SESSION['tenant_id']);
                    if ($tenantData && !empty($tenantData['slug'])) {
                        $logoutRedirect = '/j/' . $tenantData['slug'];
                    }
                } catch (\Throwable $e) {
                    // Fall through to /login
                }
            }
        }

        session_unset();
        session_destroy();
        redirect($logoutRedirect);
    }

    // Find view file
    $viewFile = $viewMap[$route] ?? null;

    // Handle dynamic routes (e.g., superadmin/tenant/1)
    if ($viewFile === null && str_starts_with($route, 'superadmin/tenant/')) {
        $viewFile = 'superadmin/tenant_detail.php';
    }
    
    // Redirect old email routes to new settings page
    if ($viewFile === null && ($route === 'superadmin/email-settings' || $route === 'superadmin/email-templates')) {
        // Redirect to settings page with appropriate tab parameter
        $tab = ($route === 'superadmin/email-settings') ? 'email' : 'templates';
        header('Location: ' . BASE_URL . '/superadmin/settings?tab=' . $tab);
        exit;
    }

    if ($viewFile === null) {
        http_response_code(404);
        require VIEWS_PATH . 'shared/header.php';
        echo '<div class="container" style="text-align:center;padding:4rem"><h1>404</h1><p>Pagina niet gevonden</p><a href="/" class="btn btn-primary">Terug naar home</a></div>';
        require VIEWS_PATH . 'shared/footer.php';
        exit;
    }

    // Enforce auth for protected views
    $publicViews = ['shared/login.php', 'shared/register.php', 'shared/setup-password.php'];
    if (!in_array($viewFile, $publicViews) && !isLoggedIn()) {
        redirect(getGuestLoginUrl());
    }

    // Enforce email verification for guest views
    // Only active if the migration has been run (email_verified_at column exists)
    // Any guest with unverified email is blocked, regardless of verification_code state
    if (isLoggedIn() && ($role === 'guest')) {
        $guestViewsRequiringVerification = [
            'guest/dashboard.php',
            'guest/wallet.php',
            'guest/qr.php',
            'guest/scan.php',
            'guest/inbox.php',
            'guest/benefits.php',
            'guest/profile/index.php',
            'guest/pin-setup.php',
        ];

        if (in_array($viewFile, $guestViewsRequiringVerification)) {
            require_once __DIR__ . '/models/User.php';
            $db = Database::getInstance()->getConnection();
            $userModel = new User($db);
            $user = $userModel->findById(currentUserId());

            // Only enforce if:
            // 1. The email_verified_at column exists (migration has been run)
            // 2. The user has NOT verified their email yet
            // Pre-existing users (column doesn't exist) are NOT blocked
            $needsVerification = (
                $user
                && array_key_exists('email_verified_at', $user)
                && empty($user['email_verified_at'])
            );

            if ($needsVerification) {
                // Redirect to email verification page
                $tenantSlug = $_SESSION['tenant']['slug'] ?? '';
                if (!empty($tenantSlug)) {
                    redirect('/j/' . $tenantSlug . '/verify');
                } else {
                    // Fallback: logout and redirect to login
                    redirect('/logout');
                }
            }
        }
    }

    // Enforce strict role-based access (each role sees only its own views)
    $roleViews = [
        'guest'      => ['guest/dashboard.php', 'guest/wallet.php', 'guest/qr.php', 'guest/scan.php', 'guest/inbox.php', 'guest/benefits.php', 'guest/profile/index.php', 'guest/pin-setup.php'],
        'bartender'  => ['bartender/dashboard.php', 'bartender/scanner.php', 'bartender/payment.php'],
        'admin'      => ['admin/dashboard.php', 'admin/reports.php', 'admin/users.php', 'admin/tiers.php', 'admin/settings.php', 'admin/marketing.php', 'admin/push.php'],
        'superadmin' => ['superadmin/dashboard.php', 'superadmin/tenants.php', 'superadmin/tenant_detail.php', 'superadmin/fees.php', 'superadmin/invoices.php', 'superadmin/settings.php', 'superadmin/migrate.php', 'superadmin/repair_deposits.php', 'superadmin/emergency_token.php'],
    ];

    $effectiveRole = effectiveRole();
    $actualRole    = currentUserRole();

    // Find which role owns this view
    $viewOwnerRole = null;
    foreach ($roleViews as $ownerRole => $views) {
        if (in_array($viewFile, $views, true)) {
            $viewOwnerRole = $ownerRole;
            break;
        }
    }

    if ($viewOwnerRole !== null) {
        $canAccess = ($effectiveRole === $viewOwnerRole);

        // Superadmin can always access their own superadmin views (even when viewing_as)
        if (!$canAccess && $actualRole === 'superadmin' && $viewOwnerRole === 'superadmin') {
            $canAccess = true;
        }

        if (!$canAccess) {
            $dashboardMap = [
                'superadmin' => '/superadmin',
                'admin'      => '/admin',
                'bartender'  => '/scan',
                'guest'      => '/dashboard',
            ];
            redirect($dashboardMap[$effectiveRole] ?? '/login');
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
