<?php
declare(strict_types=1);

/**
 * QR Service
 * HMAC-SHA256 signed QR code generation and validation
 * Payload: "user_id|tenant_id|timestamp|nonce"
 * QR data: base64(payload) + "." + hex(signature)
 */

class QrService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a new HMAC-signed QR payload (legacy — guest identity QR)
     * @return array{qr_data: string, expires_at: int}
     */
    public function generate(int $userId, int $tenantId): array
    {
        $secretKey = $this->getTenantSecretKey($tenantId);

        $timestamp = time();
        $nonce = bin2hex(random_bytes(QR_NONCE_LENGTH));

        // Build payload: "user_id|tenant_id|timestamp|nonce"
        $payload = "{$userId}|{$tenantId}|{$timestamp}|{$nonce}";

        // Sign with HMAC-SHA256
        $signature = hash_hmac('sha256', $payload, $secretKey);

        // QR data = base64(payload) + "." + hex(signature)
        $qrData = base64_encode($payload) . '.' . $signature;

        return [
            'qr_data'    => $qrData,
            'expires_at' => $timestamp + QR_EXPIRY_SECONDS,
        ];
    }

    /**
     * Generate a POS payment QR code
     * Format: "POS:{session_token}"
     *
     * The session_token is 64 hex chars (32 bytes of cryptographic randomness).
     * Server-side validation looks up the token in the database and checks
     * tenant_id, expiry, and status. No HMAC needed — the token itself
     * is unguessable and tied to a specific tenant/session.
     *
     * Previous format was "POS:" + base64(payload) + "." + HMAC (~221 chars).
     * This was too long for reliable scanning at small QR sizes.
     * New format is ~68 chars — much easier to scan.
     *
     * @return array{qr_data: string, expires_at: int}
     */
    public function generatePosQr(string $sessionToken, int $tenantId, string $tenantName = ''): array
    {
        $timestamp = time();

        // Simple format: just the session token with POS prefix
        // ~68 chars total — easily scannable even at small sizes
        $qrData = 'POS:' . $sessionToken;

        return [
            'qr_data'    => $qrData,
            'expires_at' => $timestamp + POS_SESSION_EXPIRY_SECONDS,
        ];
    }

    /**
     * Validate a POS payment QR code
     *
     * Supports two formats:
     * - New (short): "POS:{session_token}" — ~68 chars
     * - Old (long): "POS:" + base64(payload) + "." + HMAC — ~221 chars
     *
     * For the new format, the session_token is looked up in the database
     * by the caller (scan_payment.php) which checks tenant, expiry, status.
     *
     * @return array{valid: true, session_token: string, tenant_id: int|null, tenant_name: string}|array{valid: false, error: string}
     */
    public function validatePosQr(string $qrData): array
    {
        // Must start with "POS:"
        if (!str_starts_with($qrData, 'POS:')) {
            return ['valid' => false, 'error' => 'Geen betalings-QR'];
        }

        $qrBody = substr($qrData, 4);

        // New short format: just a 64-char hex session token
        // Example: "POS:a1b2c3d4...x64"
        if (preg_match('/^[0-9a-f]{64}$/i', $qrBody)) {
            return [
                'valid'         => true,
                'session_token' => $qrBody,
                'tenant_id'     => null,  // Caller looks this up from DB
                'tenant_name'   => '',    // Caller gets this from tenant record
            ];
        }

        // Old long format: base64(payload) + "." + HMAC signature
        // Backward compatibility for sessions created before this change
        $parts = explode('.', $qrBody, 2);
        if (count($parts) !== 2) {
            return ['valid' => false, 'error' => 'Ongeldig QR formaat'];
        }

        [$encodedPayload, $providedSignature] = $parts;

        $payload = base64_decode($encodedPayload, true);
        if ($payload === false) {
            return ['valid' => false, 'error' => 'Ongeldige QR codering'];
        }

        $segments = explode('|', $payload);
        // Expect at least 4 segments (legacy) or 5+ (with tenant_name)
        if (count($segments) < 4) {
            return ['valid' => false, 'error' => 'Ongeldige QR inhoud'];
        }

        [$sessionToken, $tenantIdStr, $timestampStr, $nonce] = $segments;

        // Tenant name = everything from segment 4 onwards (joined back with |)
        $tenantName = '';
        if (count($segments) >= 5) {
            $tenantName = implode('|', array_slice($segments, 4));
        }

        $tenantId = (int) $tenantIdStr;
        $timestamp = (int) $timestampStr;

        if (empty($sessionToken) || $tenantId <= 0 || $timestamp <= 0) {
            return ['valid' => false, 'error' => 'Ongeldige QR data'];
        }

        // Check expiry (5 minutes for POS QRs)
        if ($timestamp <= (time() - POS_SESSION_EXPIRY_SECONDS)) {
            return ['valid' => false, 'error' => 'QR code verlopen'];
        }

        // Check not in future (30s clock drift tolerance)
        if ($timestamp > (time() + 30)) {
            return ['valid' => false, 'error' => 'QR timestamp ongeldig'];
        }

        $secretKey = $this->getTenantSecretKey($tenantId);
        if ($secretKey === null) {
            return ['valid' => false, 'error' => 'Locatie niet gevonden'];
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return ['valid' => false, 'error' => 'Ongeldige QR handtekening'];
        }

        return [
            'valid'         => true,
            'session_token' => $sessionToken,
            'tenant_id'     => $tenantId,
            'tenant_name'   => $tenantName,
        ];
    }

    /**
     * Validate a scanned QR payload
     * @return array{valid: true, user_id: int, tenant_id: int}|array{valid: false, error: string}
     */
    public function validate(string $qrData): array
    {
        // Split QR data on "."
        $parts = explode('.', $qrData, 2);
        if (count($parts) !== 2) {
            return ['valid' => false, 'error' => 'Invalid QR format'];
        }

        [$encodedPayload, $providedSignature] = $parts;

        // Decode base64 payload
        $payload = base64_decode($encodedPayload, true);
        if ($payload === false) {
            return ['valid' => false, 'error' => 'Invalid QR encoding'];
        }

        // Parse payload: "user_id|tenant_id|timestamp|nonce"
        $segments = explode('|', $payload);
        if (count($segments) !== 4) {
            return ['valid' => false, 'error' => 'Invalid QR payload'];
        }

        [$userIdStr, $tenantIdStr, $timestampStr, $nonce] = $segments;

        // Validate types
        $userId = (int) $userIdStr;
        $tenantId = (int) $tenantIdStr;
        $timestamp = (int) $timestampStr;

        if ($userId <= 0 || $tenantId <= 0 || $timestamp <= 0) {
            return ['valid' => false, 'error' => 'Invalid QR data'];
        }

        // Check timestamp expiry (60 seconds)
        if ($timestamp <= (time() - QR_EXPIRY_SECONDS)) {
            return ['valid' => false, 'error' => 'QR code verlopen'];
        }

        // Check timestamp is not in the future (allow 30s clock drift)
        if ($timestamp > (time() + 30)) {
            return ['valid' => false, 'error' => 'QR code timestamp invalid'];
        }

        // Get tenant secret key
        $secretKey = $this->getTenantSecretKey($tenantId);
        if ($secretKey === null) {
            return ['valid' => false, 'error' => 'Tenant not found'];
        }

        // Recalculate HMAC signature
        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        // Compare using hash_equals (timing-attack safe)
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return ['valid' => false, 'error' => 'Ongeldige QR handtekening'];
        }

        return [
            'valid'     => true,
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * Get the HMAC secret key for a tenant
     */
    private function getTenantSecretKey(int $tenantId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT `secret_key` FROM `tenants` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $tenantId]);
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }
}
