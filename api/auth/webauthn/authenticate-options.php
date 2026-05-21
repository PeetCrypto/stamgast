<?php
declare(strict_types=1);

/**
 * POST /api/auth/webauthn/authenticate-options
 * Returns PublicKeyCredentialRequestOptions for WebAuthn authentication
 * Guest only (enforced by router)
 */

require_once __DIR__ . '/../../../services/WebAuthnService.php';

$userId = (int) $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();
$webAuthn = new WebAuthnService($db);

try {
    $result = $webAuthn->generateAuthenticationOptions($userId);

    if (isset($result['success']) && $result['success'] === false) {
        Response::error($result['error'] ?? 'No credentials', 'NO_CREDENTIALS', 400);
    }

    Response::success($result['data']);
} catch (\Throwable $e) {
    Response::error('Failed to generate authentication options: ' . $e->getMessage(), 'WEBAUTHN_ERROR', 500);
}
