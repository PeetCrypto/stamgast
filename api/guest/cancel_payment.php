<?php
declare(strict_types=1);

/**
 * POST /api/guest/cancel_payment
 * Guest cancels a payment session after scanning.
 *
 * Auth: guest+ (any authenticated user)
 * Middleware: CSRF (enforced by router)
 *
 * Request:  { session_token: string }
 * Response: { success }
 */

require_once __DIR__ . '/../../models/PaymentSession.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$sessionToken = trim($input['session_token'] ?? '');

if ($sessionToken === '') {
    Response::error('session_token is vereist', 'VALIDATION_ERROR', 422);
}

$userId   = currentUserId();
$tenantId = currentTenantId();

if ($userId === null || $tenantId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $sessionModel = new PaymentSession($db);

    $session = $sessionModel->findByTokenAndTenant($sessionToken, $tenantId);

    if ($session === null) {
        Response::error('Betalingssessie niet gevonden', 'NOT_FOUND', 404);
    }

    // Verify this guest owns the session
    if ((int) $session['guest_user_id'] !== $userId) {
        Response::error('Deze sessie is niet van jou', 'FORBIDDEN', 403);
    }

    // Can only cancel if in pending or scanned state
    if (!in_array($session['status'], ['pending', 'scanned'], true)) {
        Response::error('Kan deze sessie niet annuleren (status: ' . $session['status'] . ')', 'INVALID_STATUS', 400);
    }

    $ok = $sessionModel->markCancelled((int) $session['id']);

    if (!$ok) {
        Response::error('Annuleren mislukt — sessie is al verwerkt', 'CANCEL_FAILED', 400);
    }

    // Audit
    $audit = new Audit($db);
    $audit->log(
        $tenantId,
        $userId,
        'guest.session_cancelled',
        'pos_payment_session',
        (int) $session['id'],
        []
    );

    Response::success(['cancelled' => true]);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Annuleren mislukt: ' . $e->getMessage());
    }
    Response::internalError('Annuleren mislukt');
}
