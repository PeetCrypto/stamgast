<?php
declare(strict_types=1);

/**
 * POST /api/guest/confirm_payment
 * Guest confirms the payment after scanning the bartender's QR.
 * Triggers PaymentService to deduct from wallet atomically.
 *
 * Auth: guest+ (any authenticated user)
 * Middleware: CSRF (enforced by router)
 *
 * Request:  { session_token: string }
 * Response: { success, transaction_id, final_total, points_earned }
 */

require_once __DIR__ . '/../../models/PaymentSession.php';
require_once __DIR__ . '/../../services/PaymentService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$sessionToken = trim($input['session_token'] ?? '');
$tipCents = (int) ($input['tip_cents'] ?? 0);

if ($sessionToken === '') {
    Response::error('session_token is vereist', 'VALIDATION_ERROR', 422);
}
if ($tipCents < 0) {
    Response::error('tip_cents mag niet negatief zijn', 'VALIDATION_ERROR', 422);
}
if ($tipCents > 10000) {
    Response::error('Fooi mag maximaal €100,00 zijn', 'VALIDATION_ERROR', 422);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $sessionModel = new PaymentSession($db);

    // Step 1: Find session
    $session = $sessionModel->findByTokenAndTenant($sessionToken, $tenantId);

    if ($session === null) {
        Response::error('Betalingssessie niet gevonden', 'NOT_FOUND', 404);
    }

    // Step 2: Verify session is in 'scanned' state (guest already scanned it)
    if ($session['status'] !== 'scanned') {
        if ($session['status'] === 'pending') {
            Response::error('Sessie is nog niet gescand — scan de QR eerst', 'NOT_SCANNED', 400);
        }
        Response::error('Sessie is niet meer geldig (status: ' . $session['status'] . ')', 'SESSION_INVALID', 400);
    }

    // Step 3: Verify this guest owns the scan
    if ((int) $session['guest_user_id'] !== $userId) {
        Response::error('Deze sessie is niet van jou', 'FORBIDDEN', 403);
    }

    // Step 4: Check not expired
    if (strtotime($session['expires_at']) < time()) {
        $sessionModel->markFailed((int) $session['id'], 'Verlopen bij bevestiging');
        Response::error('Sessie is verlopen', 'SESSION_EXPIRED', 400);
    }

    // Step 5: Process payment via PaymentService (atomic: lock wallet, check balance, deduct, create transaction)
    $paymentService = new PaymentService($db);

    $result = $paymentService->processPayment(
        $userId,
        $tenantId,
        (int) $session['bartender_id'],
        (int) $session['amount_alc_cents'],
        (int) $session['amount_food_cents'],
        $tipCents
    );

    // Step 6: Mark session as confirmed (including tip_cents for bartender polling)
    $sessionModel->markConfirmed((int) $session['id'], $result['transaction_id'], $tipCents);

    Response::success([
        'transaction_id'   => $result['transaction_id'],
        'final_total'      => $result['final_total'],
        'discount_applied' => $result['discount_applied'],
        'points_earned'    => $result['points_earned'],
    ]);
} catch (\InvalidArgumentException $e) {
    // Mark session as failed
    if (isset($session) && $session) {
        $sessionModel->markFailed((int) $session['id'], $e->getMessage());
    }
    Response::error($e->getMessage(), 'VALIDATION_ERROR', 422);
} catch (\RuntimeException $e) {
    // Insufficient balance, etc.
    if (isset($session) && $session) {
        $sessionModel->markFailed((int) $session['id'], $e->getMessage());
    }
    Response::error($e->getMessage(), 'PAYMENT_FAILED', 400);
} catch (\Throwable $e) {
    if (isset($session) && $session) {
        $sessionModel->markFailed((int) $session['id'], 'Onverwachte fout');
    }
    if (APP_DEBUG) {
        Response::internalError('Betaling mislukt: ' . $e->getMessage());
    }
    Response::internalError('Betaling mislukt');
}
