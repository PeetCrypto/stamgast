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
                u.`birthdate`, u.`photo_url`, u.`photo_status`, u.`account_status`,
                u.`last_activity`, u.`created_at`, u.`fcm_token`,
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

        // Admin and Bartender are staff — they are always considered active,
        // only guests go through the gated onboarding (KYC-light) verification flow.
        $accountStatus = ($row['role'] !== 'guest')
            ? 'active'
            : ($row['account_status'] ?? 'unverified');

        return [
            'id'             => (int) $row['id'],
            'email'          => $row['email'],
            'role'           => $row['role'],
            'first_name'     => $row['first_name'],
            'last_name'      => $row['last_name'],
            'birthdate'      => $row['birthdate'],
            'photo_url'      => $row['photo_url'],
            'photo_status'   => $row['photo_status'],
            'account_status' => $accountStatus,
            'is_blocked'     => $row['photo_status'] === 'blocked',
            'balance_cents'  => (int) ($row['balance_cents'] ?? 0),
            'points_cents'   => (int) ($row['points_cents'] ?? 0),
            'tier_name'      => $tierName,
            'last_activity'  => $row['last_activity'],
            'created_at'     => $row['created_at'],
            'has_push'       => !empty($row['fcm_token']),
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

            // --- Send invite email for admin/bartender roles ---
            if (in_array($role, ['admin', 'bartender'], true)) {
                try {
                    require_once __DIR__ . '/../../services/Email/EmailService.php';
                    require_once __DIR__ . '/../../models/EmailTemplate.php';
                    require_once __DIR__ . '/../../models/Tenant.php';

                    $tenantModel = new Tenant($db);
                    $tenant = $tenantModel->findById($tenantId);
                    $tenantName = $tenant ? $tenant['name'] : 'REGULR';

                    $emailService = new EmailService($db);
                    $templateType = ($role === 'bartender') ? 'bartender_invite' : 'admin_invite';
                    $userName = $firstName . ' ' . $lastName;

                    // Build login URL
                    $loginUrl = FULL_BASE_URL . '/login';
                    if ($tenant && !empty($tenant['slug'])) {
                        $loginUrl = FULL_BASE_URL . '/j/' . $tenant['slug'] . '/login';
                    }

                    // Try template-based email first
                    $templateVars = [
                        'user_name'       => $userName,
                        'tenant_name'     => $tenantName,
                        'invitation_link' => $loginUrl,
                        'user_email'      => $email,
                        'user_password'   => $password,
                    ];

                    $sent = $emailService->sendTemplatedEmail(
                        $email,
                        $templateType,
                        $templateVars,
                        $tenantId,
                        'nl',
                        $tenantName
                    );

                    // Fallback to direct email if template fails
                    if (!$sent) {
                        $subject = ($role === 'bartender')
                            ? 'Jouw bartender account bij ' . $tenantName
                            : 'Jouw admin account bij ' . $tenantName;

                        $html = "<h2>Welkom bij " . htmlspecialchars($tenantName) . "!</h2>"
                            . "<p>Er is een " . ($role === 'bartender' ? 'bartender' : 'admin') . " account aangemaakt voor <strong>" . htmlspecialchars($tenantName) . "</strong>.</p>"
                            . "<p><strong>Jouw inloggegevens:</strong></p>"
                            . "<ul>"
                            . "<li>E-mail: <code>" . htmlspecialchars($email) . "</code></li>"
                            . "<li>Wachtwoord: <code>" . htmlspecialchars($password) . "</code></li>"
                            . "</ul>"
                            . "<p>Log in op jouw " . htmlspecialchars($tenantName) . " omgeving om te beginnen.</p>"
                            . "<p><em>Verander je wachtwoord na het eerste inloggen!</em></p>";

                        $text = "Welkom bij " . $tenantName . "!\n\n"
                            . "Er is een " . ($role === 'bartender' ? 'bartender' : 'admin') . " account aangemaakt voor " . $tenantName . ".\n\n"
                            . "Inloggegevens:\n"
                            . "- E-mail: " . $email . "\n"
                            . "- Wachtwoord: " . $password . "\n\n"
                            . "Verander je wachtwoord na het eerste inloggen!";

                        $emailService->sendEmail(
                            $email,
                            $subject,
                            $html,
                            $text,
                            $templateType,
                            $tenantId,
                            $newUserId,
                            $tenantName
                        );
                    }

                    error_log("Invite email sent for {$role}: {$email}");
                } catch (\Throwable $e) {
                    error_log("Invite email failed for {$role} {$email}: " . $e->getMessage());
                }
            }

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

        // Admin can only edit bartender and guest users (not other admins)
        if ($user['role'] === 'admin') {
            Response::error('Admin-gebruikers worden beheerd door de superadmin. Je kunt alleen bartenders en gasten bewerken.', 'FORBIDDEN', 403);
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
        // When promoting to staff (admin/bartender), also set account_status = 'active'
        // Staff should never have account_status = 'unverified' or 'suspended'
        if (in_array($role, ['admin', 'bartender'], true)) {
            $stmt = $db->prepare(
                "UPDATE `users`
                 SET `first_name` = :first_name, `last_name` = :last_name,
                     `email` = :email, `role` = :role, `birthdate` = :birthdate,
                     `account_status` = 'active'
                 WHERE `id` = :id AND `tenant_id` = :tid"
            );
        } else {
            $stmt = $db->prepare(
                "UPDATE `users`
                 SET `first_name` = :first_name, `last_name` = :last_name,
                     `email` = :email, `role` = :role, `birthdate` = :birthdate
                 WHERE `id` = :id AND `tenant_id` = :tid"
            );
        }
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

    // ========================================
    // ACTIVATE USER (unverified → active)
    // ========================================
    } elseif ($action === 'activate') {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Can only activate guests
        if ($user['role'] !== 'guest') {
            Response::error('Alleen gasten kunnen handmatig geactiveerd worden', 'FORBIDDEN', 403);
        }

        // Cannot activate yourself
        if ($userId === currentUserId()) {
            Response::error('Je kunt jezelf niet activeren', 'SELF_ACTIVATE', 400);
        }

        $currentStatus = $user['account_status'] ?? 'unverified';

        if ($currentStatus === 'active') {
            Response::error('Deze gast is al actief', 'ALREADY_ACTIVE', 409);
        }

        if ($currentStatus === 'suspended') {
            Response::error('Deze gast is geblokkeerd. Deblokkeer het account eerst via "Deblokkeren".', 'USER_SUSPENDED', 409);
        }

        // Activate: unverified → active (admin override, no birthdate check)
        $adminId = currentUserId();
        $stmt = $db->prepare(
            "UPDATE `users`
             SET `account_status` = 'active',
                 `verified_at` = NOW(),
                 `verified_by` = :admin_id
             WHERE `id` = :id AND `tenant_id` = :tid"
        );
        $stmt->execute([
            ':admin_id' => $adminId,
            ':id'       => $userId,
            ':tid'      => $tenantId,
        ]);

        // Audit log
        $audit->log($tenantId, $adminId, 'admin.user_activated', 'user', $userId, [
            'status_before' => $currentStatus,
            'activation_method' => 'admin_manual',
        ]);

        Response::success([
            'message' => 'Gast geactiveerd',
            'user_id' => $userId,
            'account_status' => 'active',
        ]);

    // ========================================
    // CREDIT WALLET (admin manual top-up, positive only)
    // ========================================
    } elseif ($action === 'credit_wallet') {
        $userId      = (int) ($input['user_id'] ?? 0);
        $amountCents = (int) ($input['amount_cents'] ?? 0);
        $reason      = trim($input['reason'] ?? '');

        // ── Validate inputs ──
        if ($userId <= 0) {
            Response::error('Ongeldig user_id', 'INVALID_INPUT', 400);
        }

        // CRITICAL: Only positive amounts allowed — admin can only ADD to wallet
        if ($amountCents <= 0) {
            Response::error('Bedrag moet groter zijn dan €0,00. Saldo verlagen is niet toegestaan.', 'INVALID_AMOUNT', 400);
        }

        // Sanity cap: max €10.000 per manual credit
        if ($amountCents > 1000000) {
            Response::error('Maximaal €10.000 per handmatige storting.', 'AMOUNT_TOO_LARGE', 400);
        }

        if (mb_strlen($reason) < 3) {
            Response::error('Reden is verplicht (minimaal 3 tekens). Bijv. "Contant geld ontvangen aan de bar."', 'REASON_REQUIRED', 400);
        }

        // ── Verify user belongs to this tenant ──
        $user = $userModel->findById($userId);
        if (!$user || (int) $user['tenant_id'] !== $tenantId) {
            Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
        }

        // Only guests have wallets that can be credited
        if ($user['role'] !== 'guest') {
            Response::error('Alleen gast-wallets kunnen worden opgewaardeerd', 'FORBIDDEN', 403);
        }

        $adminId = currentUserId();

        // Cannot credit your own wallet (admin is not a guest, but defense in depth)
        if ($userId === $adminId) {
            Response::error('Je kunt niet je eigen wallet opwaarderen', 'SELF_CREDIT', 400);
        }

        try {
            $db->beginTransaction();

            // ── Lock wallet row for atomic read-then-write ──
            $wallet = $walletModel->lockForUpdate($userId, $tenantId);
            if (!$wallet) {
                $db->rollBack();
                Response::error('Wallet niet gevonden voor deze gebruiker', 'WALLET_NOT_FOUND', 404);
            }

            $balanceBefore = (int) $wallet['balance_cents'];

            // ── Credit wallet (positive delta only) ──
            $updated = $walletModel->updateBalance($userId, $amountCents, 0, $tenantId);
            if (!$updated) {
                $db->rollBack();
                Response::error('Wallet kon niet worden bijgewerkt', 'WALLET_UPDATE_FAILED', 500);
            }

            // ── Create correction transaction ──
            $txId = $txModel->create([
                'tenant_id'         => $tenantId,
                'user_id'           => $userId,
                'bartender_id'      => null,
                'type'              => 'correction',
                'amount_alc_cents'  => 0,
                'amount_food_cents' => 0,
                'discount_alc_cents'  => 0,
                'discount_food_cents' => 0,
                'final_total_cents' => $amountCents,
                'points_earned'     => 0,
                'points_used'       => 0,
                'ip_address'        => getClientIP(),
                'device_fingerprint'=> null,
                'mollie_payment_id' => null,
                'description'       => 'Admin handmatige storting: ' . $reason,
            ]);

            // ── Update transaction with performed_by and admin_reason ──
            $stmt = $db->prepare(
                "UPDATE `transactions` SET `performed_by` = :admin_id, `admin_reason` = :reason WHERE `id` = :tx_id"
            );
            $stmt->execute([
                ':admin_id' => $adminId,
                ':reason'   => $reason,
                ':tx_id'    => $txId,
            ]);

            // ── Insert into wallet_credit_log (immutable audit trail) ──
            $balanceAfter = $balanceBefore + $amountCents;
            $stmt = $db->prepare(
                'INSERT INTO `wallet_credit_log`
                 (`tenant_id`, `guest_user_id`, `admin_user_id`, `transaction_id`,
                  `amount_cents`, `balance_before`, `balance_after`, `reason`,
                  `ip_address`, `user_agent`)
                 VALUES
                 (:tenant_id, :guest_user_id, :admin_user_id, :transaction_id,
                  :amount_cents, :balance_before, :balance_after, :reason,
                  :ip_address, :user_agent)'
            );
            $stmt->execute([
                ':tenant_id'      => $tenantId,
                ':guest_user_id'  => $userId,
                ':admin_user_id'  => $adminId,
                ':transaction_id' => $txId,
                ':amount_cents'   => $amountCents,
                ':balance_before' => $balanceBefore,
                ':balance_after'  => $balanceAfter,
                ':reason'         => $reason,
                ':ip_address'     => getClientIP(),
                ':user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $db->commit();

            // ── Audit log ──
            $audit->log($tenantId, $adminId, 'admin.wallet_credit', 'user', $userId, [
                'amount_cents'   => $amountCents,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reason'         => $reason,
                'transaction_id' => $txId,
            ]);

            Response::success([
                'message'        => 'Saldo succesvol opgewaardeerd',
                'user_id'        => $userId,
                'amount_cents'   => $amountCents,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'transaction_id' => $txId,
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Wallet opwaarderen mislukt: ' . $e->getMessage(), 'CREDIT_FAILED', 500);
        }

    } else {
        Response::error('Ongeldige actie. Gebruik: create, update, block, unblock, activate, credit_wallet, reset_password', 'INVALID_ACTION', 400);
    }

} else {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}
