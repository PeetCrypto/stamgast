<?php
declare(strict_types=1);

/**
 * POST /api/auth/webauthn/register
 * Verifies WebAuthn registration response and stores credential
 * Guest only (enforced by router)
 */

require_once __DIR__ . '/../../../services/WebAuthnService.php';

$input = getJsonInput();

// Validate required fields
$id = $input['id'] ?? '';
$rawId = $input['rawId'] ?? '';
$clientDataJSON = $input['response']['clientDataJSON'] ?? '';
$attestationObject = $input['response']['attestationObject'] ?? '';
$type = $input['type'] ?? '';

if (empty($id) || empty($clientDataJSON) || empty($attestationObject)) {
    Response::error('Missing required registration fields', 'INVALID_INPUT', 400);
}

if ($type !== 'public-key') {
    Response::error('Invalid credential type', 'INVALID_TYPE', 400);
}

$userId = (int) $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();
$webAuthn = new WebAuthnService($db);

try {
    $result = $webAuthn->verifyRegistration(
        $userId,
        $id,
        $clientDataJSON,
        $attestationObject
    );

    if ($result['success']) {
        Response::success([
            'credential_id' => $result['credential_id'],
        ]);
    } else {
        Response::error($result['error'] ?? 'Registration failed', 'REGISTRATION_FAILED', 400);
    }
} catch (\Throwable $e) {
    Response::error('Registration error: ' . $e->getMessage(), 'WEBAUTHN_ERROR', 500);
}
