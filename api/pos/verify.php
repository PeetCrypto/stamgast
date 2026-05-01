<?php
declare(strict_types=1);

/**
 * POST /api/pos/verify
 * Barman verifies a guest's identity by matching birthdate from ID.
 * On match: guest account_status → 'active'
 * On mismatch: log attempt, return error with attempts_remaining
 *
 * Auth: bartender+ (enforced by router)
 * Middleware: CSRF, IP whitelist (enforced by router)
 *
 * Request:  { user_id: int, birthdate: string (YYYY-MM-DD) }
 * Response: { success: true, data: { verified, user_id, birthdate_match, account_status } }
 */

require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Tenant.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$userId = (int) ($input['user_id'] ?? 0);
$birthdate = trim($input['birthdate'] ?? '');

// ── STAP 1: INPUT VALIDATIE ──────────────────────────────────
if ($userId <= 0) {
    Response::error('user_id is vereist', 'VALIDATION_ERROR', 422);
}
if ($birthdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
    Response::error('Geboortedatum is vereist (YYYY-MM-DD)', 'VALIDATION_ERROR', 422);
}

$dateValidator = new Validator();
$dateValidator->date('birthdate', $birthdate);
if (!$dateValidator->isValid()) {
    Response::error('Ongeldige geboortedatum', 'VALIDATION_ERROR', 422);
}

$tenantId = currentTenantId();
$bartenderId = currentUserId();

if ($tenantId === null || $bartenderId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();

    // ── CHECK: Is verificatie wel vereist voor deze tenant? ──
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);
    if (($tenant['verification_required'] ?? true) === false) {
        Response::error('Verificatie is niet vereist voor deze locatie', 'VERIFICATION_NOT_REQUIRED', 400);
    }

    $userModel = new User($db);

    // ── STAP 2: GAST OPHALEN + TENANT CHECK ──────────────────
    $guest = $userModel->findById($userId);
    if ($guest === null) {
        Response::error('Gebruiker niet gevonden', 'NOT_FOUND', 404);
    }
    if ((int) $guest['tenant_id'] !== $tenantId) {
        Response::error('Gebruiker behoort niet tot deze locatie', 'FORBIDDEN', 403);
    }

    // ── STAP 3: STATUS CHECK ─────────────────────────────────
    $currentStatus = $guest['account_status'] ?? 'unverified';
    if ($currentStatus === 'active') {
        Response::error('Deze gast is al geverifieerd', 'ALREADY_VERIFIED', 409);
    }
    if ($currentStatus === 'suspended') {
        Response::error('Dit account is geblokkeerd door de beheerder', 'ACCOUNT_SUSPENDED', 403);
    }

    // ── STAP 4: RATE LIMITING — PER BARMAN ───────────────────
    $tenantModel = new Tenant($db);
    $tenant = $tenantModel->findById($tenantId);
    $softLimit = (int) ($tenant['verification_soft_limit'] ?? 15);
    $hardLimit = (int) ($tenant['verification_hard_limit'] ?? 30);
    $maxAttempts = (int) ($tenant['verification_max_attempts'] ?? 2);

    // Enforce platform minimums
    if (defined('VERIFICATION_SOFT_LIMIT_MIN')) {
        $softLimit = max($softLimit, VERIFICATION_SOFT_LIMIT_MIN);
    }
    if (defined('VERIFICATION_HARD_LIMIT_MIN')) {
        $hardLimit = max($hardLimit, VERIFICATION_HARD_LIMIT_MIN);
    }

    $bartenderVerifications = $userModel->countVerificationsInWindow($tenantId, $bartenderId);
    if ($bartenderVerifications >= $hardLimit) {
        Response::error(
            'Je hebt het maximale aantal verificaties bereikt (' . $hardLimit . '/uur). Probeer het later opnieuw.',
            'RATE_LIMIT_HARD',
            429
        );
    }

    // ── STAP 5: RATE LIMITING — PER GAST ─────────────────────
    $guestAttempts = $userModel->countGuestVerificationAttempts($userId);
    if ($guestAttempts >= $maxAttempts) {
        Response::error(
            'Maximaal aantal verificatiepogingen bereikt voor deze gast. Een admin moet dit handmatig oplossen.',
            'RATE_LIMIT_GUEST',
            429
        );
    }

    // ── STAP 6: VERIFICATIE UITVOEREN ────────────────────────
    $result = $userModel->verifyUser($userId, $bartenderId, $birthdate);

    // ── STAP 7: SOFT LIMIT WARNING ───────────────────────────
    $warning = null;
    if ($bartenderVerifications >= $softLimit && $result['success']) {
        $warning = 'Waarschuwing: je nadert het verificatie limiet (' . ($bartenderVerifications + 1) . '/' . $hardLimit . ' per uur)';
    }

    // ── STAP 8: PUSH NOTIFICATIE (non-blocking) ──────────────
    if ($result['success']) {
        try {
            require_once __DIR__ . '/../../services/PushService.php';
            $pushService = new PushService($db);
            $pushService->sendNotification(
                $userId,
                $tenantId,
                'Wallet geactiveerd!',
                'Je wallet is nu actief! Stort nu je eerste saldo en geniet van je eerste biertje.'
            );
        } catch (\Throwable $e) {
            error_log('Push notification after verification failed: ' . $e->getMessage());
        }
    }

    // ── STAP 9: AUDIT LOG ────────────────────────────────────
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $bartenderId,
        $result['success'] ? 'pos.verify_success' : 'pos.verify_mismatch',
        'user',
        $userId,
        [
            'birthdate_match' => $result['data']['birthdate_match'] ?? false,
            'account_status'  => $result['data']['account_status'] ?? $currentStatus,
        ]
    );

    // ── STAP 10: RESPONSE ────────────────────────────────────
    if ($result['success']) {
        Response::success([
            'verified'        => true,
            'user_id'         => $userId,
            'birthdate_match' => true,
            'account_status'  => 'active',
            'warning'         => $warning,
        ]);
    } else {
        // Mismatch — return success=false with data (follows scan.php pattern)
        $data = $result['data'] ?? [];
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Verificatie mislukt',
            'code'    => $result['code'] ?? 'VERIFICATION_FAILED',
            'data'    => $data,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Verificatie mislukt: ' . $e->getMessage());
    }
    Response::internalError('Verificatie mislukt');
}
