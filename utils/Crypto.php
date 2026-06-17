<?php
declare(strict_types=1);

/**
 * Crypto Utility
 * Provides AES-256-GCM encryption/decryption for sensitive data at rest.
 *
 * Uses the ENCRYPTION_KEY from .env (shared between dev and prod environments,
 * but the encrypted data is only as secure as the database access controls).
 *
 * Usage:
 *   $encrypted = Crypto::encrypt('plaintext_secret');
 *   $decrypted = Crypto::decrypt($encrypted); // Returns null on failure
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Get the encryption key as raw bytes (SHA-256 of ENCRYPTION_KEY).
     */
    private static function getKey(): string
    {
        $key = getenv('ENCRYPTION_KEY') ?: '';
        if (empty($key)) {
            throw new \RuntimeException('ENCRYPTION_KEY not configured in environment');
        }
        return hash('sha256', $key, true);
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM.
     *
     * Returns a base64-encoded string containing: IV (16 bytes) + tag (16 bytes) + ciphertext.
     * This format is self-contained and can be stored in a single TEXT column.
     *
     * @param string $plaintext The data to encrypt
     * @return string Base64-encoded encrypted payload (prefix: "enc:")
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::getKey();
        $iv = random_bytes(16); // GCM uses 12 or 16 byte IV; 16 is safe
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Format: "enc:" prefix + base64(iv + tag + ciphertext)
        // The prefix allows us to detect already-encrypted vs plaintext values
        // during migration (backward compatibility).
        return 'enc:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an AES-256-GCM encrypted string.
     *
     * @param string $payload The encrypted payload (with "enc:" prefix) or plaintext (passthrough)
     * @return string|null The decrypted plaintext, or null if decryption fails.
     *                     Returns the original value if it's not encrypted (no "enc:" prefix).
     */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }

        // Backward compatibility: if the value doesn't have the "enc:" prefix,
        // it's plaintext from before encryption was enabled — return as-is.
        if (!str_starts_with($payload, 'enc:')) {
            return $payload;
        }

        $key = self::getKey();
        $raw = base64_decode(substr($payload, 4), true);

        if ($raw === false || strlen($raw) < 32) {
            // Invalid payload — too short to contain IV + tag + ciphertext
            return null;
        }

        $iv = substr($raw, 0, 16);
        $tag = substr($raw, 16, 16);
        $ciphertext = substr($raw, 32);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            error_log('[Crypto] Decryption failed: ' . openssl_error_string());
            return null;
        }

        return $plaintext;
    }

    /**
     * Check if a value appears to be encrypted (has the "enc:" prefix).
     */
    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, 'enc:');
    }
}
