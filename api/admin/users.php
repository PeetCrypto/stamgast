<?php
declare(strict_types=1);

/**
 * Admin Users API
 * GET  /api/admin/users?page=1&limit=20&search=&role=&tier=
 * POST /api/admin/users  { action: 'create'|'update'|'block'|'unblock'|'reset_password', ... }
 */

$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

// Get database connection
$db = Database::getInstance()->getConnection();

$userModel     = new User($db);
$walletModel   = new Wallet($db);
$tierModel     = new LoyaltyTier($db);
$txModel       = new Transaction($db);
$audit         = new Audit($db);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- LIST USERS ---
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = min(max(1, (int) ($_GET['limit'] ?? 20)), 100);
    $search = trim($_GET['search'] ?? '');
    $role   = trim($_GET['role'] ?? '');
    $tier   = trim($_GET['tier'] ?? '');

    $offset = ($page - 1) * $limit;

    // Build query with optional filters
    // CRITICAL: Exclude superadmin - they are platform-level, not tenant-level users
    $where  = 'WHERE u.`tenant_id` = :tid AND u.`role` != \'superadmin\'';
    $params = [':tid' => $tenantId];

    if ($search !== '') {
        $where .= ' AND (u.`first_name` LIKE :search OR u.`last_name` LIKE :search2 OR u.`email` LIKE :search3)';
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
        $params[':search3'] = '%' . $search . '%';
    }

    if ($role !== '' && in_array($role, ['superadmin', 'admin', 'bartender', 'guest'], true)) {
        $where .= ' AND u.`role` = :role';
        $params[':role'] = $role;
    }

    // Tier filter - get tier name (case-insensitive)
    $tierFilter = '';
    if ($tier !== '') {
        $tierLower = strtolower($tier);
        // Get tiers for this tenant
        $tiersStmt = $db->prepare("SELECT `name`, `min_deposit_cents` FROM `loyalty_tiers` WHERE `tenant_id` = :tid ORDER BY `min_deposit_cents` ASC");
        $tiersStmt->execute([':tid' => $tenantId]);
        $allTiers = $tiersStmt->fetchAll();
        
        // Find the tier name that matches (case-insensitive)
        foreach ($allTiers as $t) {
            if (strtolower($t['name']) === $tierLower) {
                $tierFilter = $t['name'];
                break;
            }
        }
    }

    // Count total
    $stmt = $db->prepare("SELECT COUNT(*) FROM `users` u {$where}");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Fetch page with wallet info
    $stmt = $db->prepare(
        "SELECT u.`id`, u.`email`, u.`role`, u.`first_name`, u.`last_name`,
                u.`birthdate`, u.`photo_url`, u.`photo_status`, u.`last_activity`, u.`created_at`,
                w.`balance_cents`, w.`points_cents`
         FROM `users` u
         LEFT JOIN `wallets` w ON w.`user_id` = u.`id`
         {$where}
         ORDER BY u.`created_at` DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Determine tier for each guest user
    $users = array_map(function ($row) use ($tenantId, $tierModel, $txModel) {
        $tierName = null;
        if ($row['role'] === 'guest' && $row['balance_cents'] !== null) {
            $totalDeposits = $txModel->getTotalDeposits((int) $row['id'], $tenantId);
            $tier = $tierModel->determineTier($tenantId, $totalDeposits);
            $tierName = $tier['name'];
        }

        return [
            'id'             => (int) $row['id'],
            'email'          => $row['email'],
            'role'           => $row['role'],
            'first_name'     => $row['first_name'],
            'last_name'      => $row['last_name'],
            'birthdate'      => $row['birthdate'],
            'photo_url'      => $row['photo_url'],
            'photo_status'   => $row['photo_status'],
            'is_blocked'     => $row['photo_status'] === 'blocked',
            'balance_cents'  => (int) ($row['balance_cents'] ?? 0),
            'points_cents'   => (int) ($row['points_cents'] ?? 0),
            'tier_name'      => $tierName,
            'last_activity'  => $row['last_activity'],
            'created_at'     => $row['created_at'],
        ];
    }, $rows);

    // Filter by tier in PHP (case-insensitive)
    if ($tierFilter !== '') {
        $users = array_values(array_filter($users, function($u) use ($tierFilter) {
            return $u['tier_name'] !== null && strtolower($u['tier_name']) === strtolower($tierFilter);
        }));
    }

    Response::success([
        'users' => $users,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
    ]);

} elseif ($method === 'POST') {
    $input = getJsonInput();
    $action = $input['action'] ?? '';

    // ========================================
    // CREATE USER
    // ========================================
    if ($action === 'create') {
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $email     = trim($input['email'] ?? '');
        $password  = $input['password'] ?? '';
        $role      = trim($input['role'] ?? 'guest');
        $birthdate = trim($input['birthdate'] ?? '') ?: null;

        // Validate required fields
        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            Response::error('Alle velden zijn verplicht (voornaam, achternaam, email, wachtwoord)', 'INVALID_INPUT', 400);
        }

        // Validate email
        if (!isValidEmail($email)) {
            Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
        }

        // Validate password length
        if (mb_strlen($password) < 8) {
            Response::error('Wachtwoord moet minimaal 8 tekens lang zijn', 'INVALID_PASSWORD', 400);
        }

        // Validate role - admin can only create admin/bartender/guest (never superadmin)
        $allowedRoles = ['admin', 'bartender', 'guest'];
        if (!in_array($role, $allowedRoles, true)) {
            Response::error('Ongeldige rol. Toegestaan: admin, bartender, guest', 'INVALID_ROLE', 400);
        }

        // Check email uniqueness within tenant
        if ($userModel->emailExists($email, $tenantId)) {
            Response::error('Dit e-mailadres is al in gebruik binnen deze locatie', 'EMAIL_EXISTS', 409);
        }

        // Hash password with Argon2id + pepper
        $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $passwordHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

        if ($passwordHash === false) {
            Response::error('Wachtwoord hashing mislukt', 'HASH_ERROR', 500);
        }

        try {
            $db->beginTransaction();

            // Create user
            $newUserId = $userModel->create([
                'tenant_id'     => $tenantId,
                'email'         => $email,
                'password_hash' => $passwordHash,
                'role'          => $role,
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'birthdate'     => $birthdate,
                'photo_status'  => 'unvalidated',
            ]);

            // Create wallet for the new user
            $walletModel->create($newUserId, $tenantId);

            $db->commit();

            // Audit log
            $audit->log($tenantId, currentUserId(), 'user.created', 'user', $newUserId, [
                'role'  => $role,
                'email' => $email,
            ]);

            Response::success([
                'message' => 'Gebruiker aangemaakt',
                'user_id' => $newUserId,
            ], 201);

        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('Gebruiker aanmaken mislukt: ' . $e->getMessage(), 'CREATE_FAILED', 500);
        }

    // ========================================
    // UPDATE USER
    // ========================================
    } elseif ($action === 'update') {
        $userId    = (int) ($input['user_id'] ?? 0);
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $email     = trim($input['email'] ?? '');
        $role      = trim($input['role'] ?? '');
        $birthdate = trim($input['birthdate'] ?? '') ?: null;

        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        // Verify user belongs to this tenant
        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Cannot edit superadmins
        if ($user['role'] === 'superadmin') {
            Response::error('Superadmins kunnen niet bewerkt worden', 'FORBIDDEN', 403);
        }

        // Validate role
        $allowedRoles = ['admin', 'bartender', 'guest'];
        if (!in_array($role, $allowedRoles, true)) {
            Response::error('Ongeldige rol', 'INVALID_ROLE', 400);
        }

        // Validate email if changed
        if (!isValidEmail($email)) {
            Response::error('Ongeldig e-mailadres', 'INVALID_EMAIL', 400);
        }

        // Check email uniqueness if email changed
        if ($email !== $user['email'] && $userModel->emailExists($email, $tenantId)) {
            Response::error('Dit e-mailadres is al in gebruik binnen deze locatie', 'EMAIL_EXISTS', 409);
        }

        // Update user fields
        $stmt = $db->prepare(
            "UPDATE `users`
             SET `first_name` = :first_name, `last_name` = :last_name,
                 `email` = :email, `role` = :role, `birthdate` = :birthdate
             WHERE `id` = :id AND `tenant_id` = :tid"
        );
        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':role'       => $role,
            ':birthdate'  => $birthdate,
            ':id'         => $userId,
            ':tid'        => $tenantId,
        ]);

        // Audit log
        $audit->log($tenantId, currentUserId(), 'user.update', 'user', $userId, [
            'role' => $role,
            'email' => $email,
        ]);

        Response::success([
            'message' => 'Gebruiker bijgewerkt',
            'user_id' => $userId,
        ]);

    // ========================================
    // RESET PASSWORD
    // ========================================
    } elseif ($action === 'reset_password') {
        $userId      = (int) ($input['user_id'] ?? 0);
        $newPassword = $input['new_password'] ?? '';

        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        if (mb_strlen($newPassword) < 8) {
            Response::error('Wachtwoord moet minimaal 8 tekens lang zijn', 'INVALID_PASSWORD', 400);
        }

        // Verify user belongs to this tenant
        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Cannot reset superadmin passwords
        if ($user['role'] === 'superadmin') {
            Response::error('Superadmin wachtwoorden kunnen niet hier gereset worden', 'FORBIDDEN', 403);
        }

        // Hash new password with Argon2id + pepper
        $pepperedPassword = $newPassword . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $passwordHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

        if ($passwordHash === false) {
            Response::error('Wachtwoord hashing mislukt', 'HASH_ERROR', 500);
        }

        $userModel->updatePassword($userId, $passwordHash);

        // Audit log
        $audit->log($tenantId, currentUserId(), 'user.password_reset', 'user', $userId);

        Response::success([
            'message' => 'Wachtwoord gewijzigd',
            'user_id' => $userId,
        ]);

    // ========================================
    // BLOCK USER
    // ========================================
    } elseif ($action === 'block') {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Cannot block superadmins
        if ($user['role'] === 'superadmin') {
            Response::error('Superadmins kunnen niet geblokkeerd worden', 'FORBIDDEN', 403);
        }

        // Cannot block yourself
        if ($userId === currentUserId()) {
            Response::error('Je kunt jezelf niet blokkeren', 'SELF_BLOCK', 400);
        }

        // Block by setting photo_status to blocked
        $userModel->updatePhotoStatus($userId, 'blocked');

        $audit->log($tenantId, currentUserId(), 'user.blocked', 'user', $userId);

        Response::success(['message' => 'Gebruiker geblokkeerd', 'user_id' => $userId]);

    // ========================================
    // UNBLOCK USER
    // ========================================
    } elseif ($action === 'unblock') {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        if ($user['photo_status'] !== 'blocked') {
            Response::error('Deze gebruiker is niet geblokkeerd', 'NOT_BLOCKED', 400);
        }

        // Unblock by resetting photo_status to unvalidated
        $userModel->updatePhotoStatus($userId, 'unvalidated');

        $audit->log($tenantId, currentUserId(), 'user.unblocked', 'user', $userId);

        Response::success(['message' => 'Gebruiker gedeblokkeerd', 'user_id' => $userId]);

    } else {
        Response::error('Ongeldige actie. Gebruik: create, update, block, unblock, reset_password', 'INVALID_ACTION', 400);
    }

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
