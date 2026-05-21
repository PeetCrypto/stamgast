<?php
declare(strict_types=1);

/**
 * POST /api/auth/webauthn/authenticate
 * Verifies WebAuthn authentication response
 * Guest only (enforced by router)
 */

require_once __DIR__ . '/../../../services/WebAuthnService.php';

$input = getJsonInput();

// Validate required fields
$id = $input['id'] ?? '';
$rawId = $input['rawId'] ?? '';
$clientDataJSON = $input['response']['clientDataJSON'] ?? '';
$authenticatorData = $input['response']['authenticatorData'] ?? '';
$signature = $input['response']['signature'] ?? '';
$userHandle = $input['response']['userHandle'] ?? null;
$type = $input['type'] ?? '';

if (empty($id) || empty($clientDataJSON) || empty($authenticatorData) || empty($signature)) {
    Response::error('Missing required authentication fields', 'INVALID_INPUT', 400);
}

if ($type !== 'public-key') {
    Response::error('Invalid credential type', 'INVALID_TYPE', 400);
}

$userId = (int) $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();
$webAuthn = new WebAuthnService($db);

try {
    $result = $webAuthn->verifyAuthentication(
        $userId,
        $id,
        $clientDataJSON,
        $authenticatorData,
        $signature
    );

    if ($result['success']) {
        Response::success(['authenticated' => true]);
    } else {
        Response::error($result['error'] ?? 'Authentication failed', 'AUTH_FAILED', 401);
    }
} catch (\Throwable $e) {
    Response::error('Authentication error: ' . $e->getMessage(), 'WEBAUTHN_ERROR', 500);
}
