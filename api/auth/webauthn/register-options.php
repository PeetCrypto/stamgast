<?php
declare(strict_types=1);

/**
 * POST /api/auth/webauthn/register-options
 * Returns PublicKeyCredentialCreationOptions for WebAuthn registration
 * Guest only (enforced by router)
 *
 * Als de user al een credential heeft, retourneert `already_registered: true`
 * zodat de client de flag kan zetten zonder opnieuw te registreren.
 */

require_once __DIR__ . '/../../../services/WebAuthnService.php';

$userId = (int) $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();
$webAuthn = new WebAuthnService($db);

try {
    // Check of user al een credential heeft
    $existingCredentials = $webAuthn->getUserCredentialsPublic($userId);

    if (!empty($existingCredentials)) {
        // Al geregistreerd — geen nieuwe registratie nodig
        Response::success([
            'already_registered' => true,
            'challenge' => '',
            'rp' => ['name' => '', 'id' => ''],
            'user' => ['id' => '', 'name' => '', 'displayName' => ''],
            'pubKeyCredParams' => [],
            'timeout' => 0,
            'excludeCredentials' => [],
            'authenticatorSelection' => [],
            'attestation' => 'none',
        ]);
        return;
    }

    $options = $webAuthn->generateRegistrationOptions($userId);

    Response::success($options);
} catch (\Throwable $e) {
    Response::error('Failed to generate registration options: ' . $e->getMessage(), 'WEBAUTHN_ERROR', 500);
}
