<?php
declare(strict_types=1);

/**
 * Super-Admin: Tenants CRUD Endpoint
 * GET  /api/superadmin/tenants              - List all tenants
 * GET  /api/superadmin/tenants?id=X         - Get tenant detail + stats
 * POST /api/superadmin/tenants              - Create tenant (+ admin user)
 * POST /api/superadmin/tenants action=update - Update tenant (incl NAW)
 * POST /api/superadmin/tenants action=delete - Delete tenant
 * POST /api/superadmin/tenants action=update_role - Change user role
 */

require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../models/User.php';

$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$userModel = new User($db);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Detail view: ?id=X
        $tenantId = (int) ($_GET['id'] ?? 0);
        if ($tenantId > 0) {
            handleDetail($tenantModel, $tenantId);
        } else {
            // List all tenants
            $tenants = $tenantModel->getAll();
            $safeTenants = array_map(function ($t) {
                unset($t['secret_key'], $t['mollie_api_key']);
                return $t;
            }, $tenants);
            Response::success(['tenants' => $safeTenants]);
        }
        break;

    case 'POST':
        $input = getJsonInput();
        $action = $input['action'] ?? 'create';

        switch ($action) {
            case 'create':
                handleCreate($tenantModel, $userModel, $input, $db);
                break;
            case 'update':
                handleUpdate($tenantModel, $input, $db);
                break;
            case 'delete':
                handleDelete($tenantModel, $input, $db);
                break;
            case 'update_role':
                handleUpdateRole($userModel, $input, $db);
                break;
            case 'change_password':
                handleChangePassword($userModel, $input, $db);
                break;
            case 'change_email':
                handleChangeEmail($userModel, $input, $db);
                break;
            default:
                Response::error('Invalid action. Use: create, update, delete, update_role, change_password', 'INVALID_ACTION', 400);
        }
        break;

    default:
        Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

function handleDetail(Tenant $model, int $tenantId): void
{
    $tenant = $model->findById($tenantId);
    if (!$tenant) {
        Response::notFound('Tenant niet gevonden');
    }
    unset($tenant['secret_key']);

    $stats = $model->getTenantStats($tenantId);
    $users = $model->getUsersWithWallets($tenantId);

    // Remove sensitive data from users
    $safeUsers = array_map(function ($u) {
        unset($u['password_hash']);
        return $u;
    }, $users);

    // Platform fee configuration + stats
    require_once __DIR__ . '/../../services/PlatformFeeService.php';
    $db = Database::getInstance()->getConnection();
    $feeService = new PlatformFeeService($db);
    $feeConfig = $model->getFeeConfig($tenantId);
    $feeStats = $feeService->getTenantFeeStats($tenantId);

    Response::success([
        'tenant'     => $tenant,
        'stats'      => $stats,
        'users'      => $safeUsers,
        'fee_config' => $feeConfig,
        'fee_stats'  => $feeStats,
    ]);
}

function handleCreate(Tenant $model, User $userModel, array $input, PDO $db): void
{
    require_once __DIR__ . '/../../services/Email/email_helpers.php';
    $v = new Validator();
    $v->string('name', $input['name'] ?? '', 2, 255);
    
    // Slug: convert to valid format if provided, otherwise auto-generate from name
    if (!empty($input['slug'])) {
        // Auto-convert to valid slug format
        $input['slug'] = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['slug'])));
        $input['slug'] = trim($input['slug'], '-');
    } elseif (!empty($input['name'])) {
        // Auto-generate slug from name if not provided
        $input['slug'] = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['name'])));
    }
    if (!empty($input['slug'])) {
        $v->slug('slug', $input['slug']);
    }

    if (isset($input['brand_color'])) {
        $v->hexColor('brand_color', $input['brand_color']);
    }
    if (isset($input['secondary_color'])) {
        $v->hexColor('secondary_color', $input['secondary_color']);
    }
    // Validate NAW fields — contact_email is REQUIRED for tenant creation
    if (empty($input['contact_email'])) {
        Response::error('Contact e-mailadres is verplicht', 'MISSING_EMAIL', 400);
    }
    if (!isValidEmail($input['contact_email'])) {
        Response::error('Ongeldig contact e-mailadres', 'INVALID_EMAIL', 400);
    }
    $v->validate();

    // Check slug uniqueness (use provided or auto-generated)
    $slugToCheck = $input['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['name'] ?? '')));
    $existing = $model->findBySlug($slugToCheck);
    if ($existing) {
        Response::error('Slug already in use', 'SLUG_EXISTS', 409);
    }

    try {
        $tenantId = $model->create($input);
    } catch (\Throwable $e) {
        error_log('REGULR tenant create failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Response::error(
            'Fout bij aanmaken tenant. Controleer of alle database migraties zijn uitgevoerd. Details: ' . $e->getMessage(),
            'TENANT_CREATE_FAILED',
            500
        );
    }

    // Auto-create admin user for the new tenant
    // contact_email is guaranteed to be present (validated above)
    $adminEmail = $input['contact_email'];
    $adminPassword = substr(bin2hex(random_bytes(12)), 0, 16); // 16-char random password
    $adminFirstName = $input['contact_name'] ?? 'Admin';
    $nameParts = explode(' ', trim($adminFirstName), 2);
    $firstName = $nameParts[0];
    $lastName = $nameParts[1] ?? $input['name'];

    try {
        $userModel->create([
            'tenant_id'     => $tenantId,
            'email'         => $adminEmail,
            'password_hash' => password_hash($adminPassword . APP_PEPPER, PASSWORD_ARGON2ID),
            'role'          => 'admin',
            'first_name'    => $firstName,
            'last_name'     => $lastName,
        ]);
    } catch (\Throwable $e) {
        error_log('REGULR admin user create failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        // Rollback: delete the tenant we just created
        try { $model->delete($tenantId); } catch (\Throwable $_) {}
        Response::error(
            'Fout bij aanmaken admin gebruiker. Controleer of alle database migraties zijn uitgevoerd. Details: ' . $e->getMessage(),
            'ADMIN_CREATE_FAILED',
            500
        );
    }

    // Create wallet for admin user
    $adminUserId = (int) $db->lastInsertId();
    try {
        $stmt = $db->prepare(
            'INSERT INTO `wallets` (`user_id`, `tenant_id`, `balance_cents`, `points_cents`)
             VALUES (:uid, :tid, 0, 0)'
        );
        $stmt->execute([':uid' => $adminUserId, ':tid' => $tenantId]);
    } catch (\Throwable $e) {
        error_log('REGULR wallet create failed: ' . $e->getMessage());
        // Non-fatal: wallet creation failure should not block tenant creation
    }

    // --- Send welcome email with login credentials ---
    // Try template-based email first, fall back to hardcoded HTML
    $welcomeSubject = 'REGULR.vip - Jouw inloggegevens voor ' . $input['name'];
    $welcomeHtml = "<h2>Welkom bij REGULR.vip!</h2>"
        . "<p>Er is een account aangemaakt voor <strong>" . htmlspecialchars($input['name']) . "</strong>.</p>"
        . "<p><strong>Jouw inloggegevens:</strong></p>"
        . "<ul>"
        . "<li>E-mail: <code>" . htmlspecialchars($adminEmail) . "</code></li>"
        . "<li>Wachtwoord: <code>" . htmlspecialchars($adminPassword) . "</code></li>"
        . "</ul>"
        . "<p>Log in op jouw REGULR.vip omgeving om te beginnen.</p>"
        . "<p><em>Verander je wachtwoord na het eerste inloggen!</em></p>";
    $welcomeText = "Welkom bij REGULR.vip!\n\n"
        . "Er is een account aangemaakt voor " . $input['name'] . ".\n\n"
        . "Inloggegevens:\n"
        . "- E-mail: " . $adminEmail . "\n"
        . "- Wachtwoord: " . $adminPassword . "\n\n"
        . "Verander je wachtwoord na het eerste inloggen!";

    // 1) Try template-based email first
    $emailSent = false;
    try {
        require_once __DIR__ . '/../../services/Email/EmailService.php';
        $emailService = new EmailService($db);
        
        // Try to use the tenant_welcome template from the database
        $templateResult = $emailService->sendTemplatedEmail(
            $adminEmail,
            'tenant_welcome',
            [
                'tenant_name'        => $input['name'],
                'user_name'          => $firstName . ' ' . $lastName,
                'user_email'         => $adminEmail,
                'user_password'      => $adminPassword,
                'password_reset_link' => BASE_URL . '/login',
            ],
            null,  // global template (no tenant_id)
            'nl'
        );
        if ($templateResult) {
            $emailSent = true;
            error_log('Tenant welcome template email sent to: ' . $adminEmail);
        }
    } catch (\Throwable $e) {
        error_log('Tenant welcome template email failed: ' . $e->getMessage());
    }

    // 2) Fallback: Send directly via EmailService with hardcoded HTML
    if (!$emailSent) {
        try {
            require_once __DIR__ . '/../../services/Email/EmailService.php';
            $emailService = new EmailService($db);
            $directResult = $emailService->sendEmail(
                $adminEmail,
                $welcomeSubject,
                $welcomeHtml,
                $welcomeText,
                'tenant_welcome',
                $tenantId,
                $adminUserId
            );
            if ($directResult) {
                $emailSent = true;
                error_log('Tenant welcome direct email sent to: ' . $adminEmail);
            } else {
                error_log('Tenant welcome direct email failed for: ' . $adminEmail);
            }
        } catch (\Throwable $e) {
            error_log('Tenant welcome direct email exception: ' . $e->getMessage());
        }
    }

    // 3) Also queue via email_queue table (fallback for batch processing)
    try {
        $stmt = $db->prepare(
            'INSERT INTO `email_queue` (`tenant_id`, `user_id`, `subject`, `body_html`, `status`)
             VALUES (:tid, :uid, :subject, :body, \'pending\')'
        );
        $stmt->execute([
            ':tid'     => $tenantId,
            ':uid'     => $adminUserId,
            ':subject' => $welcomeSubject,
            ':body'    => $welcomeHtml,
        ]);
    } catch (\Throwable $e) {
        error_log('email_queue insert failed: ' . $e->getMessage());
    }

    // Audit log (non-critical — wrap in try-catch)
    try {
        $audit = new Audit($db);
        $audit->log(
            0,
            currentUserId(),
            'tenant.created',
            'tenant',
            $tenantId,
            ['name' => $input['name'], 'slug' => $input['slug'], 'admin_email' => $adminEmail]
        );
    } catch (\Throwable $e) {
        error_log('REGULR audit log failed: ' . $e->getMessage());
    }

    $tenant = $model->findById($tenantId);
    unset($tenant['secret_key']);

    Response::success([
        'tenant'         => $tenant,
        'admin_email'    => $adminEmail,
        'admin_password' => $adminPassword,
    ], 201);
}

function handleUpdate(Tenant $model, array $input, PDO $db): void
{
    try {
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            Response::error('tenant_id is required', 'MISSING_FIELD', 400);
        }

        $existing = $model->findById($tenantId);
        if (!$existing) {
            Response::notFound('Tenant not found');
        }

        // Validate fields if present
        $v = new Validator();
        if (isset($input['name'])) {
            $v->string('name', $input['name'], 2, 255);
        }
        
        // Slug: convert to valid format if provided, otherwise auto-generate from name
        if (!empty($input['slug'])) {
            $input['slug'] = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['slug'])));
            $input['slug'] = trim($input['slug'], '-');
        } elseif (!empty($input['name'])) {
            $input['slug'] = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['name'])));
        }
        if (!empty($input['slug'])) {
            $v->slug('slug', $input['slug']);
        }
        if (isset($input['brand_color'])) $v->hexColor('brand_color', $input['brand_color']);
        if (isset($input['secondary_color'])) $v->hexColor('secondary_color', $input['secondary_color']);
        if (isset($input['mollie_status'])) $v->enum('mollie_status', $input['mollie_status'], ['mock', 'test', 'live']);
        if (isset($input['contact_email']) && !empty($input['contact_email'])) {
            if (!isValidEmail($input['contact_email'])) {
                Response::error('Ongeldig contact e-mailadres', 'INVALID_EMAIL', 400);
            }
        }

        // Validate fee config fields if present (super-admin only)
        if (isset($input['platform_fee_percentage'])) {
            $perc = (float) $input['platform_fee_percentage'];
            if ($perc < 0 || $perc > 25) {
                Response::error('Platform fee percentage moet tussen 0 en 25 zijn', 'INVALID_FEE_PERCENTAGE', 400);
            }
        }
        if (isset($input['platform_fee_min_cents'])) {
            $min = (int) $input['platform_fee_min_cents'];
            if ($min < 0 || $min > 100000) {
                Response::error('Minimum fee moet tussen 0 en 100000 cents zijn', 'INVALID_MIN_FEE', 400);
            }
        }
        if (isset($input['invoice_period'])) {
            if (!in_array($input['invoice_period'], ['week', 'month'], true)) {
                Response::error('Invoice period moet week of month zijn', 'INVALID_PERIOD', 400);
            }
        }
        if (isset($input['btw_number']) && !empty($input['btw_number'])) {
            if (!preg_match('/^\d{9}[A-Za-z0-9]{2}$/', $input['btw_number'])) {
                Response::error('Ongeldig BTW-nummer formaat (verwacht: 9 cijfers + 2 tekens, bijv. 123456789B01)', 'INVALID_BTW', 400);
            }
        }
        if (isset($input['mollie_connect_status'])) {
            if (!in_array($input['mollie_connect_status'], ['none', 'pending', 'active', 'suspended', 'revoked'], true)) {
                Response::error('Ongeldige Connect status', 'INVALID_CONNECT_STATUS', 400);
            }
        }

        $v->validate();

        // Check slug uniqueness if changing
        if (isset($input['slug']) && $input['slug'] !== $existing['slug']) {
            $slugCheck = $model->findBySlug($input['slug']);
            if ($slugCheck) {
                Response::error('Slug already in use', 'SLUG_EXISTS', 409);
            }
        }

        $model->update($tenantId, $input);

        try {
            $audit = new Audit($db);
            $audit->log(0, currentUserId(), 'tenant.updated', 'tenant', $tenantId, $input);
        } catch (\Throwable $e) {
            error_log('REGULR audit log failed: ' . $e->getMessage());
        }

        $updated = $model->findById($tenantId);
        unset($updated['secret_key']);

        Response::success(['tenant' => $updated]);
    } catch (\Throwable $e) {
        error_log('REGULR tenant update failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Response::error('Fout bij bijwerken tenant: ' . $e->getMessage(), 'UPDATE_FAILED', 500);
    }
}

function handleDelete(Tenant $model, array $input, PDO $db): void
{
    $tenantId = (int) ($input['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        Response::error('tenant_id is required', 'MISSING_FIELD', 400);
    }

    $existing = $model->findById($tenantId);
    if (!$existing) {
        Response::notFound('Tenant not found');
    }

    $audit = new Audit($db);
    $audit->log(0, currentUserId(), 'tenant.deleted', 'tenant', $tenantId, ['name' => $existing['name']]);

    $model->delete($tenantId);

    Response::success(['deleted' => true]);
}

function handleUpdateRole(User $userModel, array $input, PDO $db): void
{
    $userId = (int) ($input['user_id'] ?? 0);
    $newRole = $input['role'] ?? '';
    $allowedRoles = ['admin', 'bartender', 'guest'];

    if ($userId <= 0) {
        Response::error('user_id is required', 'MISSING_FIELD', 400);
    }
    if (!in_array($newRole, $allowedRoles, true)) {
        Response::error('Ongeldige rol. Gebruik: admin, bartender, guest', 'INVALID_ROLE', 400);
    }

    $user = $userModel->findById($userId);
    if (!$user) {
        Response::notFound('Gebruiker niet gevonden');
    }
    if ($user['role'] === 'superadmin') {
        Response::error('Kan superadmin rol niet wijzigen', 'FORBIDDEN', 403);
    }

    $userModel->updateRole($userId, $newRole);

    $audit = new Audit($db);
    $audit->log(
        (int) $user['tenant_id'],
        currentUserId(),
        'user.role_changed',
        'user',
        $userId,
        ['old_role' => $user['role'], 'new_role' => $newRole]
    );

    Response::success(['user_id' => $userId, 'new_role' => $newRole]);
}

function handleChangePassword(User $userModel, array $input, PDO $db): void
{
    $userId = (int) ($input['user_id'] ?? 0);
    $email = $input['email'] ?? '';
    $tenantId = (int) ($input['tenant_id'] ?? 0);
    $newPassword = $input['new_password'] ?? '';

    // Validate required fields
    if (empty($email) && $userId <= 0) {
        Response::error('E-mail of user_id is verplicht', 'MISSING_FIELD', 400);
    }
    
    if (empty($newPassword)) {
        Response::error('Nieuw wachtwoord is verplicht', 'MISSING_FIELD', 400);
    }

    // Find user by email if user_id not provided
    if ($userId <= 0 && !empty($email)) {
        $user = $userModel->findByEmail($email, $tenantId);
        if (!$user) {
            Response::error('Gebruiker niet gevonden met opgegeven e-mail', 'USER_NOT_FOUND', 404);
        }
        $userId = (int) $user['id'];
    }

    // Validate user exists
    if ($userId > 0) {
        $user = $userModel->findById($userId);
        if (!$user) {
            Response::error('Gebruiker niet gevonden', 'USER_NOT_FOUND', 404);
        }
        
        // Check if user belongs to the specified tenant
        if ((int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker behoort niet tot deze tenant', 'USER_NOT_IN_TENANT', 403);
        }
    }

    // Validate password strength (at least 8 characters)
    if (strlen($newPassword) < 8) {
        Response::error('Wachtwoord moet minimaal 8 tekens bevatten', 'PASSWORD_TOO_SHORT', 400);
    }

    // Update password
    $passwordHash = password_hash($newPassword . APP_PEPPER, PASSWORD_ARGON2ID);
    $userModel->updatePassword($userId, $passwordHash);

    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        currentUserId(),
        'user.password_changed',
        'user',
        $userId,
        ['user_email' => $user['email'] ?? '']
    );

    Response::success(['user_id' => $userId, 'message' => 'Wachtwoord succesvol gewijzigd']);
}

function handleChangeEmail(User $userModel, array $input, PDO $db): void
{
    $userId = (int) ($input['user_id'] ?? 0);
    $tenantId = (int) ($input['tenant_id'] ?? 0);
    $newEmail = trim($input['new_email'] ?? '');

    // Validate required fields
    if ($userId <= 0) {
        Response::error('user_id is verplicht', 'MISSING_FIELD', 400);
    }
    if ($tenantId <= 0) {
        Response::error('tenant_id is verplicht', 'MISSING_FIELD', 400);
    }
    if (empty($newEmail)) {
        Response::error('Nieuw e-mailadres is verplicht', 'MISSING_FIELD', 400);
    }
    if (!isValidEmail($newEmail)) {
        Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
    }

    // Find user
    $user = $userModel->findById($userId);
    if (!$user) {
        Response::error('Gebruiker niet gevonden', 'USER_NOT_FOUND', 404);
    }

    // Check if user belongs to the specified tenant
    if ((int) $user['tenant_id'] !== $tenantId) {
        Response::error('Gebruiker behoort niet tot deze tenant', 'USER_NOT_IN_TENANT', 403);
    }

    // Cannot edit superadmins
    if ($user['role'] === 'superadmin') {
        Response::error('Kan superadmin e-mail niet wijzigen', 'FORBIDDEN', 403);
    }

    // Superadmin can ONLY change email of admin users
    // Bartenders/gasten worden beheerd door de admin van de tenant
    if ($user['role'] !== 'admin') {
        Response::error(
            'Superadmin kan alleen e-mail van admin-gebruikers wijzigen. Bartenders en gasten worden beheerd door de admin.',
            'FORBIDDEN',
            403
        );
    }

    // No change needed
    if ($newEmail === $user['email']) {
        Response::success(['user_id' => $userId, 'message' => 'E-mailadres is al ingesteld']);
    }

    // Check email uniqueness within tenant
    if ($userModel->emailExists($newEmail, $tenantId)) {
        Response::error('Dit e-mailadres is al in gebruik binnen deze tenant', 'EMAIL_EXISTS', 409);
    }

    // Update email
    $userModel->updateEmail($userId, $newEmail);

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        currentUserId(),
        'user.email_changed',
        'user',
        $userId,
        ['old_email' => $user['email'], 'new_email' => $newEmail]
    );

    Response::success(['user_id' => $userId, 'message' => 'E-mailadres succesvol gewijzigd']);
}
