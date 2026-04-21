<?php
declare(strict_types=1);

/**
 * POST /api/pos/process_payment
 * Process a payment from the bartender POS — the "Hufterproof" 6-step flow.
 *
 * ALL financial calculations happen SERVER-SIDE. The client is a view-only terminal.
 * Step 1: Input validation
 * Step 2: User & tier lookup
 * Step 3: Discount calculation (alcohol capped at 25% by Dutch law)
 * Step 4: Balance check (double-spend protection)
 * Step 5: Atomic transaction (PDO beginTransaction -> commit)
 * Step 6: Response + audit
 *
 * Auth: bartender+ (enforced by router)
 * Middleware: CSRF (enforced by router)
 *
 * Request:  { user_id: int, amount_alc_cents: int, amount_food_cents: int }
 * Response: { success, transaction_id, final_total, discount_applied, points_earned }
 */

require_once __DIR__ . '/../../services/PaymentService.php';

if ($method !== 'POST') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

$userId        = (int) ($input['user_id'] ?? 0);
$amountAlc     = (int) ($input['amount_alc_cents'] ?? 0);
$amountFood    = (int) ($input['amount_food_cents'] ?? 0);

// --- Input validation ---
if ($userId <= 0) {
    Response::error('Ongeldig gebruiker ID', 'VALIDATION_ERROR', 422);
}
if ($amountAlc < 0 || $amountFood < 0) {
    Response::error('Bedragen mogen niet negatief zijn', 'VALIDATION_ERROR', 422);
}
if ($amountAlc === 0 && $amountFood === 0) {
    Response::error('Er moet minimaal één bedrag ingevuld zijn', 'VALIDATION_ERROR', 422);
}

$tenantId     = currentTenantId();
$bartenderId  = currentUserId();

if ($tenantId === null || $bartenderId === null) {
    Response::unauthorized();
}

try {
    $db = Database::getInstance()->getConnection();
    $paymentService = new PaymentService($db);

    $result = $paymentService->processPayment(
        $userId,
        $tenantId,
        $bartenderId,
        $amountAlc,
        $amountFood
    );

    Response::success($result);
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 'VALIDATION_ERROR', 422);
} catch (\RuntimeException $e) {
    // Insufficient balance, DB errors, etc.
    Response::error($e->getMessage(), 'PAYMENT_FAILED', 400);
} catch (\Throwable $e) {
    if (APP_DEBUG) {
        Response::internalError('Betaling mislukt: ' . $e->getMessage());
    }
    Response::internalError('Betaling mislukt');
}
