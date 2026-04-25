<?php
declare(strict_types=1);

/**
 * Auth Service
 * Handles login, registration, and session management
 * Uses Argon2id for password hashing
 */

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Tenant.php';

class AuthService
{
    private User $userModel;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->userModel = new User($db);
    }

    /**
     * Authenticate a user by email and password
     * For superadmins: searches globally (no tenant filter)
     * For tenant users: searches within the given tenant
     * Returns user data on success, null on failure
     */
    public function login(string $email, string $password, ?int $tenantId = null): ?array
    {
        // First try global search — superadmins have tenant_id = NULL
        $user = $this->userModel->findByEmailGlobal($email);

        if ($user === null) {
            return null;
        }

        // If user is a superadmin, no tenant context needed
        if ($user['role'] === 'superadmin') {
            // Superadmins don't belong to any tenant — skip tenant check
        } elseif ($tenantId !== null) {
            // Tenant user: verify they belong to the specified tenant
            if ((int) $user['tenant_id'] !== $tenantId) {
                return null;
            }
        } else {
            // No tenant context provided and user is not superadmin
            return null;
        }

        // Check if user is blocked (photo_status = 'blocked' is used as block mechanism)
        if (($user['photo_status'] ?? '') === 'blocked') {
            return null;
        }

        // Verify password with Argon2id (with optional pepper)
        $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
        if (!password_verify($pepperedPassword, $user['password_hash'])) {
            return null;
        }

        // Check if Argon2id needs rehash (cost parameters changed)
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->rehashPassword($user['id'], $password);
        }

        // Update last activity
        $this->userModel->updateLastActivity($user['id']);

        return $user;
    }

    /**
     * Register a new guest user
     * @return array{success: bool, user_id?: int, error?: string}
     */
    public function register(array $data, int $tenantId): array
    {
        // Validate required fields
        $required = ['email', 'password', 'first_name', 'last_name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Field '{$field}' is required"];
            }
        }

        // Validate email format
        if (!isValidEmail($data['email'])) {
            return ['success' => false, 'error' => 'Ongeldig e-mailadres'];
        }

        // Validate password strength
        $v = new Validator();
        $v->password('password', $data['password']);
        if (!$v->isValid()) {
            return ['success' => false, 'error' => implode(', ', $v->getErrors())];
        }

        // Check email uniqueness per tenant
        if ($this->userModel->emailExists($data['email'], $tenantId)) {
            return ['success' => false, 'error' => 'Dit e-mailadres is al geregistreerd'];
        }

        // Validate birthdate and age (18+)
        $birthdate = $data['birthdate'] ?? null;
        if ($birthdate !== null && $birthdate !== '') {
            $dateValidator = new Validator();
            $dateValidator->date('birthdate', $birthdate);
            if (!$dateValidator->isValid()) {
                return ['success' => false, 'error' => 'Ongeldige geboortedatum'];
            }
            if (!isAdult($birthdate)) {
                return ['success' => false, 'error' => 'Je moet minimaal 18 jaar oud zijn'];
            }
        }

        // Hash password with Argon2id + pepper
        $pepperedPassword = $data['password'] . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $passwordHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

        if ($passwordHash === false) {
            return ['success' => false, 'error' => 'Wachtwoord hashing mislukt'];
        }

        // Create user + wallet in atomic transaction
        try {
            $this->db->beginTransaction();

            $userId = $this->userModel->create([
                'tenant_id'     => $tenantId,
                'email'         => trim($data['email']),
                'password_hash' => $passwordHash,
                'role'          => 'guest',
                'first_name'    => trim($data['first_name']),
                'last_name'     => trim($data['last_name']),
                'birthdate'     => $birthdate ?: null,
            ]);

            // Create wallet for the new user
            $this->createWallet($userId, $tenantId);

            $this->db->commit();

            return ['success' => true, 'user_id' => $userId];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Registratie mislukt, probeer opnieuw'];
        }
    }

    /**
     * Start a secure session for the given user
     * Regenerates session ID to prevent session fixation
     * Superadmins get no tenant context (platform-level, multi-tenant)
     */
    public function startSession(array $user, ?array $tenant = null): void
    {
        // Regenerate session ID (anti-session-fixation)
        regenerateSession();

        // Set core session data
        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['tenant_id']     = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        $_SESSION['role']          = $user['role'];
        $_SESSION['first_name']    = $user['first_name'];
        $_SESSION['last_name']     = $user['last_name'];
        $_SESSION['last_activity'] = time();

        // Superadmins operate at platform level — no tenant branding
        if ($user['role'] === 'superadmin') {
            $_SESSION['tenant_name']      = defined('APP_NAME') ? APP_NAME : 'STAMGAST';
            $_SESSION['brand_color']      = '#FFC107';
            $_SESSION['secondary_color']  = '#FF9800';
            $_SESSION['tenant_logo']      = '';
            $_SESSION['tenant']           = null;
            return;
        }

        // Set tenant branding in session (for header.php)
        if ($tenant !== null) {
            $_SESSION['tenant_name']      = $tenant['name'];
            $_SESSION['brand_color']      = $tenant['brand_color'];
            $_SESSION['secondary_color']  = $tenant['secondary_color'];
            $_SESSION['tenant_logo']      = $tenant['logo_path'] ?? '';
            $_SESSION['tenant']           = $tenant;
        } else {
            // Load tenant data if not provided
            $tenantModel = new Tenant($this->db);
            $tenantData = $tenantModel->findById((int) $user['tenant_id']);
            if ($tenantData) {
                $_SESSION['tenant_name']     = $tenantData['name'];
                $_SESSION['brand_color']     = $tenantData['brand_color'] ?? '#FFC107';
                $_SESSION['secondary_color'] = $tenantData['secondary_color'] ?? '#FF9800';
                $_SESSION['tenant_logo']     = $tenantData['logo_path'] ?? '';
                $_SESSION['tenant']          = $tenantData;
            }
        }
    }

    /**
     * Destroy the current session
     */
    public function logout(): void
    {
        $_SESSION = [];

        // Delete session cookie if it exists
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Get current session info for the frontend
     */
    public function getSessionInfo(): array
    {
        if (!isLoggedIn()) {
            return [
                'authenticated' => false,
                'user'          => null,
            ];
        }

        $user = $this->userModel->getPublicProfile((int) $_SESSION['user_id']);

        return [
            'authenticated' => true,
            'user'          => $user ? [
                'id'         => $user['id'],
                'role'       => $user['role'],
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name'],
                'email'      => $user['email'],
                'tenant_id'  => $user['tenant_id'],
                'photo_url'  => $user['photo_url'],
            ] : null,
        ];
    }

    /**
     * Rehash a password with updated Argon2id parameters
     */
    private function rehashPassword(int $userId, string $password): void
    {
        $pepperedPassword = $password . (defined('APP_PEPPER') ? APP_PEPPER : '');
        $newHash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

        if ($newHash !== false) {
            $stmt = $this->db->prepare('UPDATE `users` SET `password_hash` = :hash WHERE `id` = :id');
            $stmt->execute([
                ':hash' => $newHash,
                ':id'   => $userId,
            ]);
        }
    }

    /**
     * Create a wallet for a new user
     */
    private function createWallet(int $userId, int $tenantId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `wallets` (`user_id`, `tenant_id`, `balance_cents`, `points_cents`)
             VALUES (:user_id, :tenant_id, 0, 0)'
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':tenant_id' => $tenantId,
        ]);
    }
}
