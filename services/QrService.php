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
     * Generate a new HMAC-signed QR payload
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
