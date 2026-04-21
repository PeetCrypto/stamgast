<?php
declare(strict_types=1);

/**
 * Admin Users API
 * GET  /api/admin/users?page=1&limit=20&search=&role=
 * POST /api/admin/users  { action: 'update'|'block', user_id, ... }
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- LIST USERS ---
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = min(max(1, (int) ($_GET['limit'] ?? 20)), 100);
    $search = trim($_GET['search'] ?? '');
    $role   = trim($_GET['role'] ?? '');

    $offset = ($page - 1) * $limit;

    // Build query with optional filters
    $where  = 'WHERE u.`tenant_id` = :tid';
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

    // Count total
    $stmt = $db->prepare("SELECT COUNT(*) FROM `users` u {$where}");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Fetch page with wallet info
    $stmt = $db->prepare(
        "SELECT u.`id`, u.`email`, u.`role`, u.`first_name`, u.`last_name`,
                u.`photo_url`, u.`photo_status`, u.`last_activity`, u.`created_at`,
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
            'photo_url'      => $row['photo_url'],
            'photo_status'   => $row['photo_status'],
            'balance_cents'  => (int) ($row['balance_cents'] ?? 0),
            'points_cents'   => (int) ($row['points_cents'] ?? 0),
            'tier_name'      => $tierName,
            'last_activity'  => $row['last_activity'],
            'created_at'     => $row['created_at'],
        ];
    }, $rows);

    Response::success([
        'users' => $users,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
    ]);

} elseif ($method === 'POST') {
    // --- UPDATE USER ---
    $input = getJsonInput();
    $action = $input['action'] ?? '';

    if ($action === 'update') {
        $userId   = (int) ($input['user_id'] ?? 0);
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $email     = trim($input['email'] ?? '');
        $role      = trim($input['role'] ?? '');

        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        // Verify user belongs to this tenant
        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Validate role
        $allowedRoles = ['admin', 'bartender', 'guest'];
        if (!in_array($role, $allowedRoles, true)) {
            Response::error('Ongeldige rol', 'INVALID_ROLE', 400);
        }

        // Update user fields
        $stmt = $db->prepare(
            "UPDATE `users`
             SET `first_name` = :first_name, `last_name` = :last_name,
                 `email` = :email, `role` = :role
             WHERE `id` = :id AND `tenant_id` = :tid"
        );
        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':role'       => $role,
            ':id'         => $userId,
            ':tid'        => $tenantId,
        ]);

        // Audit log
        (new Audit($db))->log($tenantId, currentUserId(), 'user.update', 'user', $userId, [
            'role' => $role,
            'email' => $email,
        ]);

        Response::success([
            'message' => 'Gebruiker bijgewerkt',
            'user_id' => $userId,
        ]);

    } elseif ($action === 'block') {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Block by setting photo_status to blocked (simple MVP block mechanism)
        $stmt = $db->prepare(
            "UPDATE `users` SET `photo_status` = 'blocked' WHERE `id` = :id AND `tenant_id` = :tid"
        );
        $stmt->execute([':id' => $userId, ':tid' => $tenantId]);

        (new Audit($db))->log($tenantId, currentUserId(), 'user.blocked', 'user', $userId);

        Response::success(['message' => 'Gebruiker geblokkeerd', 'user_id' => $userId]);

    } else {
        Response::error('Ongeldige actie. Gebruik: update, block', 'INVALID_ACTION', 400);
    }

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
