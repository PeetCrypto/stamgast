<?php
declare(strict_types=1);

/**
 * Super-Admin: Superadmin User Management API
 *
 * GET  /api/superadmin/admins                        → List all superadmins
 * POST /api/superadmin/admins  action=create          → Create new superadmin
 * POST /api/superadmin/admins  action=change_password → Change superadmin password
 * POST /api/superadmin/admins  action=delete          → Delete a superadmin
 */

require_once __DIR__ . '/../../models/User.php';

$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleList($db);
        break;

    case 'POST':
        $input = getJsonInput();
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                handleCreate($userModel, $input, $db);
                break;
            case 'change_password':
                handleChangePassword($userModel, $input, $db);
                break;
            case 'delete':
                handleDelete($userModel, $input, $db);
                break;
            default:
                Response::error('Invalid action. Use: create, change_password, delete', 'INVALID_ACTION', 400);
        }
        break;

    default:
        Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// ─── Handlers ────────────────────────────────────────────────────────────────

/**
 * List all superadmin accounts (no password_hash)
 */
function handleList(PDO $db): void
{
    $stmt = $db->query(
        "SELECT id, email, first_name, last_name, account_status, created_at
         FROM users
         WHERE role = 'superadmin'
         ORDER BY created_at ASC"
    );
    $admins = $stmt->fetchAll();

    Response::success(['admins' => $admins]);
}

/**
 * Create a new superadmin account
 */
function handleCreate(User $userModel, array $input, PDO $db): void
{
    $email     = trim($input['email'] ?? '');
    $password  = $input['password'] ?? '';
    $firstName = trim($input['first_name'] ?? '');
    $lastName  = trim($input['last_name'] ?? '');

    // Validate required fields
    if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
        Response::error('Alle velden zijn verplicht (email, password, first_name, last_name)', 'MISSING_FIELDS', 400);
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
    }

    // Validate password strength (min 8 chars, same as existing change_password logic)
    if (strlen($password) < 8) {
        Response::error('Wachtwoord moet minimaal 8 tekens bevatten', 'PASSWORD_TOO_SHORT', 400);
    }

    // Check email uniqueness globally (superadmins have tenant_id = NULL)
    $existing = $userModel->findByEmailGlobal($email);
    if ($existing) {
        Response::error('Er bestaat al een account met dit e-mailadres', 'EMAIL_EXISTS', 409);
    }

    // Hash password with Argon2id + APP_PEPPER (same as AuthService / deploy.php)
    $hash = password_hash($password . APP_PEPPER, PASSWORD_ARGON2ID);

    $stmt = $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role, first_name, last_name, account_status)
         VALUES (NULL, ?, ?, 'superadmin', ?, ?, 'active')"
    );
    $stmt->execute([$email, $hash, $firstName, $lastName]);
    $newId = (int) $db->lastInsertId();

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        0,
        currentUserId(),
        'superadmin.created',
        'user',
        $newId,
        ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName]
    );

    Response::success([
        'id'      => $newId,
        'email'   => $email,
        'message' => 'Superadmin succesvol aangemaakt',
    ], 201);
}

/**
 * Change a superadmin's password
 */
function handleChangePassword(User $userModel, array $input, PDO $db): void
{
    $userId      = (int) ($input['user_id'] ?? 0);
    $newPassword = $input['new_password'] ?? '';

    if ($userId <= 0) {
        Response::error('user_id is verplicht', 'MISSING_FIELD', 400);
    }
    if (strlen($newPassword) < 8) {
        Response::error('Wachtwoord moet minimaal 8 tekens bevatten', 'PASSWORD_TOO_SHORT', 400);
    }

    // Verify target is a superadmin
    $user = $userModel->findById($userId);
    if (!$user || $user['role'] !== 'superadmin') {
        Response::error('Superadmin niet gevonden', 'NOT_FOUND', 404);
    }

    // Hash and update
    $hash = password_hash($newPassword . APP_PEPPER, PASSWORD_ARGON2ID);
    $userModel->updatePassword($userId, $hash);

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        0,
        currentUserId(),
        'superadmin.password_changed',
        'user',
        $userId,
        ['email' => $user['email']]
    );

    Response::success(['message' => 'Wachtwoord succesvol gewijzigd']);
}

/**
 * Delete a superadmin account
 * Security: cannot delete yourself, cannot delete the last superadmin
 */
function handleDelete(User $userModel, array $input, PDO $db): void
{
    $userId = (int) ($input['user_id'] ?? 0);

    if ($userId <= 0) {
        Response::error('user_id is verplicht', 'MISSING_FIELD', 400);
    }

    // Cannot delete yourself
    if ($userId === currentUserId()) {
        Response::error('Je kunt je eigen account niet verwijderen', 'CANNOT_DELETE_SELF', 400);
    }

    // Verify target is a superadmin
    $user = $userModel->findById($userId);
    if (!$user || $user['role'] !== 'superadmin') {
        Response::error('Superadmin niet gevonden', 'NOT_FOUND', 404);
    }

    // Prevent deleting the last superadmin
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'");
    $count = (int) $stmt->fetchColumn();
    if ($count <= 1) {
        Response::error('Kan de laatste superadmin niet verwijderen. Er moet altijd minimaal 1 superadmin zijn.', 'LAST_ADMIN', 400);
    }

    // Delete the user
    $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'superadmin'");
    $stmt->execute([$userId]);

    // Audit log
    $audit = new Audit($db);
    $audit->log(
        0,
        currentUserId(),
        'superadmin.deleted',
        'user',
        $userId,
        ['email' => $user['email']]
    );

    Response::success(['deleted' => true]);
}
