<?php
declare(strict_types=1);

/**
 * WebAuthn Service
 * Hand-rolled WebAuthn (FIDO2) implementation voor FaceID/fingerprint authenticatie
 * Geen Composer dependencies — alles from scratch met OpenSSL
 *
 * Ondersteunt:
 * - Registration (navigator.credentials.create)
 * - Authentication (navigator.credentials.get)
 * - ES256 (ECDSA P-256) en RS256 signatures
 * - Counter-based replay protection
 */
class WebAuthnService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ======================================================================
    // REGISTRATION
    // ======================================================================

    /**
     * Genereer registration challenge en retourneer PublicKeyCredentialCreationOptions
     */
    public function generateRegistrationOptions(int $userId): array
    {
        // Genereer random challenge (32 bytes)
        $challenge = $this->generateBase64urlChallenge(32);

        // Sla challenge op in database
        $this->storeChallenge($userId, $challenge, 'registration');

        // Haal bestaande credentials op (voor excludeCredentials)
        $existingCredentials = $this->getUserCredentials($userId);

        $excludeCredentials = array_map(function ($cred) {
            return [
                'type' => 'public-key',
                'id' => $cred['credential_id'],
                'transports' => json_decode($cred['transports'] ?? '["internal"]', true) ?? ['internal'],
            ];
        }, $existingCredentials);

        // User handle: base64url encoded user ID
        $userHandle = $this->base64urlEncode((string) $userId);

        return [
            'challenge' => $challenge,
            'rp' => [
                'name' => WEBAUTHN_RP_NAME,
                'id' => $this->getRpId(),
            ],
            'user' => [
                'id' => $userHandle,
                'name' => (string) $userId,
                'displayName' => (string) $userId,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256 (ECDSA P-256)
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'timeout' => WEBAUTHN_CHALLENGE_TIMEOUT * 1000, // ms
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification' => WEBAUTHN_USER_VERIFICATION,
                'residentKey' => 'discouraged',
                'requireResidentKey' => false,
            ],
            'attestation' => 'none',
        ];
    }

    /**
     * Verifieer registration response en sla credential op
     *
     * @param int $userId
     * @param string $id Credential ID (base64url)
     * @param string $clientDataJSON Base64url encoded
     * @param string $attestationObject Base64url encoded
     * @return array{success: bool, credential_id?: string, error?: string}
     */
    public function verifyRegistration(int $userId, string $id, string $clientDataJSON, string $attestationObject): array
    {
        // 1. Haal en valideer challenge
        $challenge = $this->getAndDeleteChallenge($userId, 'registration');
        if ($challenge === null) {
            return ['success' => false, 'error' => 'No pending registration challenge'];
        }

        // 2. Decode clientDataJSON
        $clientDataRaw = $this->base64urlDecode($clientDataJSON);
        $clientData = json_decode($clientDataRaw, true);
        if (!$clientData) {
            return ['success' => false, 'error' => 'Invalid clientDataJSON'];
        }

        // 3. Valideer clientData
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            return ['success' => false, 'error' => 'Invalid clientData type'];
        }

        $expectedChallenge = $challenge;
        $actualChallenge = $clientData['challenge'] ?? '';
        if ($actualChallenge !== $expectedChallenge) {
            return ['success' => false, 'error' => 'Challenge mismatch'];
        }

        if (!$this->validateOrigin($clientData['origin'] ?? '')) {
            return ['success' => false, 'error' => 'Invalid origin'];
        }

        // 4. Parse attestationObject (CBOR)
        $attestationRaw = $this->base64urlDecode($attestationObject);
        $parsed = $this->parseAttestationObject($attestationRaw);

        if ($parsed === null) {
            return ['success' => false, 'error' => 'Failed to parse attestationObject'];
        }

        // 5. Extract credential ID en public key
        $credentialId = $parsed['credentialId'] ?? null;
        $publicKey = $parsed['publicKey'] ?? null;
        $signCount = $parsed['signCount'] ?? 0;
        $aaguid = $parsed['aaguid'] ?? '';

        error_log("[WebAuthn] Registration: credentialId=" . ($credentialId ? 'present' : 'NULL') . ", publicKey=" . ($publicKey ? strlen($publicKey) . ' bytes' : 'NULL'));

        if ($credentialId === null || $publicKey === null) {
            return ['success' => false, 'error' => 'Missing credential data in attestationObject'];
        }
        
        // Debug: log public key format
        error_log("[WebAuthn] Registration: publicKey first byte=0x" . bin2hex(substr($publicKey, 0, 1)));

        // 6. Valideer dat credential ID overeenkomt met de id parameter
        $credentialIdB64 = $this->base64urlEncode($credentialId);
        if ($credentialIdB64 !== $id) {
            return ['success' => false, 'error' => 'Credential ID mismatch'];
        }

        // 7. Controleer of credential niet al bestaat
        if ($this->credentialExists($credentialIdB64)) {
            return ['success' => false, 'error' => 'Credential already registered'];
        }

        // 8. Sla credential op in database
        // IMPORTANT: Store public_key as base64url encoded string (NOT raw bytes)
        // It will be decoded when needed for verification
        $this->storeCredential([
            'user_id' => $userId,
            'credential_id' => $credentialIdB64,
            'public_key' => $this->base64urlEncode($publicKey),
            'counter' => $signCount,
            'aaguid' => $aaguid ? $this->base64urlEncode($aaguid) : null,
            'transports' => json_encode(['internal']),
        ]);

        return [
            'success' => true,
            'credential_id' => $credentialIdB64,
        ];
    }

    // ======================================================================
    // AUTHENTICATION
    // ======================================================================

    /**
     * Genereer authentication challenge en retourneer PublicKeyCredentialRequestOptions
     */
    public function generateAuthenticationOptions(int $userId): array
    {
        // Genereer random challenge
        $challenge = $this->generateBase64urlChallenge(32);

        // Sla challenge op
        $this->storeChallenge($userId, $challenge, 'authentication');

        // Haal gebruiker's credentials op
        $credentials = $this->getUserCredentials($userId);

        if (empty($credentials)) {
            return ['success' => false, 'error' => 'No credentials registered'];
        }

        $allowCredentials = array_map(function ($cred) {
            return [
                'type' => 'public-key',
                'id' => $cred['credential_id'],
                'transports' => json_decode($cred['transports'] ?? '["internal"]', true) ?? ['internal'],
            ];
        }, $credentials);

        return [
            'success' => true,
            'data' => [
                'challenge' => $challenge,
                'rpId' => $this->getRpId(),
                'allowCredentials' => $allowCredentials,
                'timeout' => WEBAUTHN_CHALLENGE_TIMEOUT * 1000,
                'userVerification' => WEBAUTHN_USER_VERIFICATION,
            ],
        ];
    }

    /**
     * Verifieer authentication response
     *
     * @param int $userId
     * @param string $credentialId Base64url
     * @param string $clientDataJSON Base64url
     * @param string $authenticatorData Base64url
     * @param string $signature Base64url
     * @return array{success: bool, error?: string}
     */
    public function verifyAuthentication(int $userId, string $credentialId, string $clientDataJSON, string $authenticatorData, string $signature): array
    {
        // 1. Haal challenge
        $challenge = $this->getAndDeleteChallenge($userId, 'authentication');
        if ($challenge === null) {
            error_log("[WebAuthn] FAIL step 1: No pending authentication challenge for user $userId");
            return ['success' => false, 'error' => 'No pending authentication challenge'];
        }

        // 2. Haal credential op uit database
        $credential = $this->getCredentialById($credentialId);
        if ($credential === null || (int) $credential['user_id'] !== $userId) {
            error_log("[WebAuthn] FAIL step 2: Unknown credential $credentialId for user $userId");
            return ['success' => false, 'error' => 'Unknown credential'];
        }
        
        // Debug: log public key info
        $pubKeyLen = strlen($credential['public_key'] ?? '');
        error_log("[WebAuthn] step 2: public_key length = $pubKeyLen bytes");

        // 3. Decode clientDataJSON
        $clientDataRaw = $this->base64urlDecode($clientDataJSON);
        $clientData = json_decode($clientDataRaw, true);
        if (!$clientData) {
            error_log("[WebAuthn] FAIL step 3: Invalid clientDataJSON");
            return ['success' => false, 'error' => 'Invalid clientDataJSON'];
        }

        // 4. Valideer clientData
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            error_log("[WebAuthn] FAIL step 4a: Invalid clientData type: " . ($clientData['type'] ?? 'missing'));
            return ['success' => false, 'error' => 'Invalid clientData type'];
        }

        if (($clientData['challenge'] ?? '') !== $challenge) {
            error_log("[WebAuthn] FAIL step 4b: Challenge mismatch");
            return ['success' => false, 'error' => 'Challenge mismatch'];
        }

        $origin = $clientData['origin'] ?? '';
        if (!$this->validateOrigin($origin)) {
            error_log("[WebAuthn] FAIL step 4c: Invalid origin: $origin (allowed: " . rtrim(FULL_BASE_URL, '/') . ")");
            return ['success' => false, 'error' => 'Invalid origin'];
        }

        // 5. Parse authenticatorData
        $authDataRaw = $this->base64urlDecode($authenticatorData);
        $authDataParsed = $this->parseAuthenticatorData($authDataRaw);

        if ($authDataParsed === null) {
            error_log("[WebAuthn] FAIL step 5: Failed to parse authenticatorData");
            return ['success' => false, 'error' => 'Failed to parse authenticatorData'];
        }

        // 6. Valideer RP ID hash
        $expectedRpIdHash = hash('sha256', $this->getRpId(), true);
        if ($authDataParsed['rpIdHash'] !== $expectedRpIdHash) {
            error_log("[WebAuthn] FAIL step 6: RP ID hash mismatch. Expected: " . bin2hex($expectedRpIdHash) . " Got: " . bin2hex($authDataParsed['rpIdHash']) . " RP ID: " . $this->getRpId());
            return ['success' => false, 'error' => 'RP ID hash mismatch'];
        }

        // 7. Controleer userVerified flag (we eisen userVerification=required)
        if (!$authDataParsed['userVerified']) {
            error_log("[WebAuthn] FAIL step 7: User verification not performed");
            return ['success' => false, 'error' => 'User verification required but not performed'];
        }

        // 8. Counter replay protection
        $newCounter = $authDataParsed['signCount'];
        $storedCounter = (int) $credential['counter'];
        if ($newCounter !== 0 && $newCounter <= $storedCounter) {
            error_log("[WebAuthn] FAIL step 8: Counter rollback. New: $newCounter Stored: $storedCounter");
            return ['success' => false, 'error' => 'Counter rollback detected — possible cloned authenticator'];
        }

        // 9. Verifieer signature
        $signatureRaw = $this->base64urlDecode($signature);
        $publicKeyRaw = $this->base64urlDecode($credential['public_key']);
        
        error_log("[WebAuthn] step 9: publicKeyRaw length=" . strlen($publicKeyRaw) . " bytes, first byte=0x" . bin2hex(substr($publicKeyRaw, 0, 1)));

        // Bouw signed data: authenticatorData + SHA-256(clientDataJSON)
        $signedData = $authDataRaw . hash('sha256', $clientDataRaw, true);

        $verified = $this->verifySignature($publicKeyRaw, $signedData, $signatureRaw);

        if (!$verified) {
            error_log("[WebAuthn] FAIL step 9: Signature verification failed for credential $credentialId");
            return ['success' => false, 'error' => 'Signature verification failed'];
        }

        // 10. Update counter en last_used_at
        $this->updateCredentialCounter($credentialId, $newCounter);

        error_log("[WebAuthn] SUCCESS: Authentication verified for user $userId");
        return ['success' => true];
    }

    // ======================================================================
    // CBOR PARSING (minimal subset for WebAuthn)
    // ======================================================================

    /**
     * Parse attestationObject (CBOR format)
     * We only support 'none' attestation format (most common for platform authenticators)
     *
     * Returns: ['credentialId' => string, 'publicKey' => string, 'signCount' => int, 'aaguid' => string]
     */
    private function parseAttestationObject(string $data): ?array
    {
        $offset = 0;
        $map = $this->cborDecode($data, $offset);

        if (!is_array($map)) {
            return null;
        }

        // attestationObject bevat: fmt, attStmt, authData
        $fmt = $map['fmt'] ?? '';
        $authData = $map['authData'] ?? '';

        // We only support 'none' attestation
        if ($fmt !== 'none' && $fmt !== 'packed') {
            // For 'none' format, attStmt is empty which is fine
            // For 'packed', we'd need to verify the attestation signature
            // Platform authenticators (FaceID) typically use 'none'
        }

        // Parse authData (binary format)
        if (strlen($authData) < 37) {
            return null;
        }

        $result = $this->parseAuthenticatorData($authData);
        if ($result === null) {
            return null;
        }

        // Als er credential data is (attestedCredentialData flag = bit 6)
        if (($result['flags'] & 0x40) && isset($result['credentialData'])) {
            return $result['credentialData'];
        }

        return null;
    }

    /**
     * Parse authenticatorData binary format
     *
     * Structure:
     *   rpIdHash (32 bytes)
     *   flags (1 byte)
     *   signCount (4 bytes, big-endian)
     *   [attestedCredentialData] (if flags bit 6 set)
     *   [extensions] (if flags bit 7 set)
     */
    private function parseAuthenticatorData(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 37) {
            return null;
        }

        $rpIdHash = substr($data, 0, 32);
        $flags = ord($data[32]);
        $signCount = unpack('N', substr($data, 33, 4))[1];

        $result = [
            'rpIdHash' => $rpIdHash,
            'flags' => $flags,
            'userPresent' => ($flags & 0x01) !== 0,
            'userVerified' => ($flags & 0x04) !== 0,
            'signCount' => $signCount,
        ];

        $offset = 37;

        // Attested Credential Data (bit 6 = 0x40)
        if ($flags & 0x40) {
            if ($len < $offset + 18) {
                return null;
            }

            $aaguid = substr($data, $offset, 16);
            $offset += 16;

            // Credential ID length (2 bytes, big-endian)
            $credIdLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;

            // Credential ID
            if ($len < $offset + $credIdLen) {
                return null;
            }
            $credentialId = substr($data, $offset, $credIdLen);
            $offset += $credIdLen;

            // COSE public key (CBOR encoded)
            $coseKeyRaw = substr($data, $offset);
            $coseOffset = 0;
            $coseKey = $this->cborDecode($coseKeyRaw, $coseOffset);
            $publicKeyBytes = $this->extractPublicKeyFromCOSE($coseKey);

            if ($publicKeyBytes === null) {
                return null;
            }

            $result['credentialData'] = [
                'aaguid' => $aaguid,
                'credentialId' => $credentialId,
                'publicKey' => $publicKeyBytes,
                'signCount' => $signCount,
            ];
        }

        return $result;
    }

    /**
     * Extract raw public key bytes from COSE key map
     * Supports ES256 (alg -7, crv 1 = P-256) and RS256 (alg -257)
     */
    private function extractPublicKeyFromCOSE(?array $coseKey): ?string
    {
        if ($coseKey === null) {
            return null;
        }

        // Debug: log entire COSE key structure
        error_log("[WebAuthn] extractPublicKeyFromCOSE: COSE key keys: " . implode(', ', array_keys($coseKey)));
        
        $kty = $coseKey[1] ?? null; // kty
        
        // Try string key as well
        if ($kty === null && isset($coseKey['1'])) {
            $kty = $coseKey['1'];
        }
        
        error_log("[WebAuthn] extractPublicKeyFromCOSE: kty=$kty (type: " . gettype($kty) . ")");

        if ($kty === 2 || $kty === '2') {
            // EC2 key type (ES256 / P-256)
            $x = $this->getCoseByteString($coseKey, -2);
            $y = $this->getCoseByteString($coseKey, -3);

            error_log("[WebAuthn] extractPublicKeyFromCOSE: x=" . ($x ? strlen($x) . ' bytes' : 'NULL') . ", y=" . ($y ? strlen($y) . ' bytes' : 'NULL'));

            if ($x === null || $y === null) {
                return null;
            }

            // Uncompressed EC point: 0x04 || x || y
            $result = "\x04" . $x . $y;
            error_log("[WebAuthn] extractPublicKeyFromCOSE: result=" . strlen($result) . " bytes, first byte=" . ord($result[0]));
            return $result;
        }

        if ($kty === 3 || $kty === '3') {
            // RSA key type (RS256)
            $n = $this->getCoseByteString($coseKey, -1);
            $e = $this->getCoseByteString($coseKey, -2);

            if ($n === null || $e === null) {
                return null;
            }

            // RSA public key in DER format
            return $this->encodeRsaPublicKey($n, $e);
        }

        return null;
    }

    /**
     * Get byte string value from COSE key map
     * COSE keys use integers as map keys, but PHP arrays may have string keys
     */
    private function getCoseByteString(array $coseKey, int $key): ?string
    {
        // Try both integer and string key access
        $val = $coseKey[$key] ?? $coseKey[(string)$key] ?? null;
        
        if ($val === null) {
            // Log all available keys for debugging
            error_log("[WebAuthn] getCoseByteString: key $key not found. Available keys: " . implode(', ', array_keys($coseKey)));
            return null;
        }
        
        // CBOR byte string is returned as raw bytes, but could also be a CBOR byte string object (array with type info)
        if (is_string($val)) {
            return $val;
        }
        
        // If it's an array (nested CBOR structure), extract the actual byte string
        // CBOR byte string format: [major_type (2), length, data]
        // Or it could be: [major_type (2), length]
        if (is_array($val)) {
            // Try different array structures
            if (isset($val[2]) && is_string($val[2])) {
                return $val[2]; // Full format: [2, length, data]
            }
            if (isset($val[1]) && is_string($val[1])) {
                return $val[1]; // Alternative: [2, data]
            }
            // Maybe it's a simple array with just the data at index 0
            $firstVal = reset($val);
            if (is_string($firstVal)) {
                return $firstVal;
            }
            error_log("[WebAuthn] getCoseByteString: array format unknown, keys: " . implode(', ', array_keys($val)));
        }
        
        return null;
    }

    /**
     * Encode RSA public key in DER format for OpenSSL
     */
    private function encodeRsaPublicKey(string $n, string $e): string
    {
        // DER encode: SEQUENCE { SEQUENCE { OID, NULL }, BIT STRING }
        $modulus = $this->derEncodeLength($n);
        $exponent = $this->derEncodeLength($e);

        $rsaSeq = $this->derEncodeSequence($modulus . $exponent);

        // RSA OID: 1.2.840.113549.1.1.1
        $oid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // BIT STRING wrapper
        $bitString = "\x00" . $rsaSeq;
        $bitStringEncoded = "\x03" . $this->derEncodeLengthBytes(strlen($bitString)) . $bitString;

        $sequence = $oid . $bitStringEncoded;

        return "\x30" . $this->derEncodeLengthBytes(strlen($sequence)) . $sequence;
    }

    private function derEncodeLength(string $data): string
    {
        return "\x02" . $this->derEncodeLengthBytes(strlen($data)) . $data;
    }

    private function derEncodeLengthBytes(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        if ($len < 256) {
            return "\x81" . chr($len);
        }
        return "\x82" . pack('n', $len);
    }

    private function derEncodeSequence(string $data): string
    {
        return "\x30" . $this->derEncodeLengthBytes(strlen($data)) . $data;
    }

    // ======================================================================
    // SIGNATURE VERIFICATION
    // ======================================================================

    /**
     * Verify WebAuthn signature using OpenSSL
     * Supports ES256 (ECDSA P-256 with SHA-256) and RS256 (RSASSA-PKCS1-v1_5 with SHA-256)
     */
    private function verifySignature(string $publicKeyRaw, string $data, string $signature): bool
    {
        $keyLen = strlen($publicKeyRaw);
        $firstByte = $keyLen > 0 ? bin2hex($publicKeyRaw[0]) : 'empty';
        error_log("[WebAuthn] verifySignature: keyLen=$keyLen, firstByte=0x$firstByte");
        
        // Bepaal key type op basis van lengte en formaat
        // EC P-256 uncompressed point: 0x04 + 32 + 32 = 65 bytes
        if ($keyLen === 65 && $publicKeyRaw[0] === "\x04") {
            return $this->verifyES256($publicKeyRaw, $data, $signature);
        }

        // RSA: DER encoded public key
        if ($keyLen > 65) {
            return $this->verifyRS256($publicKeyRaw, $data, $signature);
        }

        error_log("[WebAuthn] verifySignature: Unknown key format, cannot verify");
        return false;
    }

    /**
     * Verify ES256 signature (ECDSA P-256 with SHA-256)
     */
    private function verifyES256(string $publicKeyRaw, string $data, string $signature): bool
    {
        // Converteer uncompressed point naar PEM formaat
        // Correct: 0x04 || x (32 bytes) || y (32 bytes) = 65 bytes totaal
        if (strlen($publicKeyRaw) !== 65 || $publicKeyRaw[0] !== "\x04") {
            error_log("[WebAuthn] verifyES256: Invalid public key format");
            return false;
        }
        
        $x = substr($publicKeyRaw, 1, 32);
        $y = substr($publicKeyRaw, 33, 32);

        // Bouw DER EC public key - use proper SubjectPublicKeyInfo format
        // OID for prime256v1 (secp256r1)
        $ecOid = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        
        // BIT STRING containing the uncompressed point
        $point = "\x04" . $x . $y;
        $bitStringContent = "\x00" . $point;  // 0x00 = no unused bits
        $bitString = "\x03" . $this->derEncodeLengthBytes(strlen($bitStringContent)) . $bitStringContent;
        
        // SEQUENCE { OID, BIT STRING }
        $seq = $ecOid . $bitString;
        $derKey = "\x30" . $this->derEncodeLengthBytes(strlen($seq)) . $seq;

        $pem = "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($derKey), 64, "\n") .
               "-----END PUBLIC KEY-----\n";

        error_log("[WebAuthn] verifyES256: PEM key generated");

        // Signature is already in DER format from WebAuthn
        $result = openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256);
        error_log("[WebAuthn] verifyES256: openssl_verify result=" . $result);
        
        if ($result === -1) {
            error_log("[WebAuthn] verifyES256: OpenSSL error: " . openssl_error_string());
        }
        
        return $result === 1;
    }

    /**
     * Verify RS256 signature (RSASSA-PKCS1-v1_5 with SHA-256)
     */
    private function verifyRS256(string $publicKeyDer, string $data, string $signature): bool
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($publicKeyDer), 64, "\n") .
               "-----END PUBLIC KEY-----\n";

        $result = openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Convert WebAuthn raw signature (r || s) to DER-encoded signature for OpenSSL
     * WebAuthn returns raw r||s, but OpenSSL expects DER-encoded SEQUENCE { INTEGER r, INTEGER s }
     */
    private function signatureRawToDer(string $rawSig): string
    {
        $len = strlen($rawSig);
        error_log("[WebAuthn] signatureRawToDer: input length=" . $len);
        
        if ($len < 2) {
            error_log("[WebAuthn] signatureRawToDer: signature too short!");
            return $rawSig;
        }

        $halfLen = (int) ($len / 2);
        $r = substr($rawSig, 0, $halfLen);
        $s = substr($rawSig, $halfLen);

        error_log("[WebAuthn] signatureRawToDer: r=" . bin2hex(substr($r, 0, 8)) . "..., s=" . bin2hex(substr($s, 0, 8)) . "...");

        // Remove leading zeros, then add 0x00 if high bit set (positive integer)
        $r = ltrim($r, "\x00");
        if (strlen($r) === 0) $r = "\x00";
        if (ord($r[0] ?? "\x00") & 0x80) {
            $r = "\x00" . $r;
        }

        $s = ltrim($s, "\x00");
        if (strlen($s) === 0) $s = "\x00";
        if (ord($s[0] ?? "\x00") & 0x80) {
            $s = "\x00" . $s;
        }

        $rEnc = "\x02" . $this->derEncodeLengthBytes(strlen($r)) . $r;
        $sEnc = "\x02" . $this->derEncodeLengthBytes(strlen($s)) . $s;

        $result = "\x30" . $this->derEncodeLengthBytes(strlen($rEnc) + strlen($sEnc)) . $rEnc . $sEnc;
        error_log("[WebAuthn] signatureRawToDer: output length=" . strlen($result));
        return $result;
    }

    // ======================================================================
    // MINIMAL CBOR DECODER (subset needed for WebAuthn)
    // ======================================================================

    /**
     * Decode CBOR data at given offset, advancing offset
     * Supports: unsigned int, negative int, byte string, text string, array, map
     */
    private function cborDecode(string $data, int &$offset)
    {
        if ($offset >= strlen($data)) {
            return null;
        }

        $header = ord($data[$offset]);
        $majorType = ($header >> 5) & 0x07;
        $additionalInfo = $header & 0x1F;

        $offset++;

        // Decode argument (length/value)
        $argument = $this->cborDecodeArgument($data, $offset, $additionalInfo);

        switch ($majorType) {
            case 0: // Unsigned integer
                return $argument;

            case 1: // Negative integer
                return -1 - $argument;

            case 2: // Byte string
                $result = substr($data, $offset, $argument);
                $offset += $argument;
                return $result;

            case 3: // Text string
                $result = substr($data, $offset, $argument);
                $offset += $argument;
                return $result;

            case 4: // Array
                $array = [];
                for ($i = 0; $i < $argument; $i++) {
                    $array[] = $this->cborDecode($data, $offset);
                }
                return $array;

            case 5: // Map
                $map = [];
                for ($i = 0; $i < $argument; $i++) {
                    $key = $this->cborDecode($data, $offset);
                    $value = $this->cborDecode($data, $offset);
                    // Keys can be integers or strings
                    $map[$key] = $value;
                }
                return $map;

            case 7: // Simple values / float
                if ($additionalInfo === 20) return false;
                if ($additionalInfo === 21) return true;
                if ($additionalInfo === 22) return null;
                return $argument;

            default:
                return null;
        }
    }

    private function cborDecodeArgument(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }
        if ($additionalInfo === 24) {
            $val = ord($data[$offset]);
            $offset++;
            return $val;
        }
        if ($additionalInfo === 25) {
            $val = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $val;
        }
        if ($additionalInfo === 26) {
            $val = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $val;
        }
        if ($additionalInfo === 27) {
            // 64-bit: we only use lower 32 bits for WebAuthn purposes
            $val = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
            return (int) $val;
        }
        return 0;
    }

    // ======================================================================
    // DATABASE HELPERS
    // ======================================================================

    private function generateBase64urlChallenge(int $bytes): string
    {
        return $this->base64urlEncode(random_bytes($bytes));
    }

    private function storeChallenge(int $userId, string $challenge, string $type): void
    {
        // Verwijder eerdere challenges van dit type voor deze user
        $stmt = $this->db->prepare(
            'DELETE FROM `webauthn_challenges` WHERE `user_id` = :uid AND `type` = :type'
        );
        $stmt->execute([':uid' => $userId, ':type' => $type]);

        // Sla nieuwe challenge op
        $stmt = $this->db->prepare(
            'INSERT INTO `webauthn_challenges` (`user_id`, `challenge`, `type`, `expires_at`)
             VALUES (:uid, :challenge, :type, DATE_ADD(NOW(), INTERVAL :timeout SECOND))'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':challenge' => $challenge,
            ':type' => $type,
            ':timeout' => WEBAUTHN_CHALLENGE_TIMEOUT,
        ]);
    }

    private function getAndDeleteChallenge(int $userId, string $type): ?string
    {
        // Haal challenge op
        $stmt = $this->db->prepare(
            'SELECT `challenge` FROM `webauthn_challenges`
             WHERE `user_id` = :uid AND `type` = :type AND `expires_at` > NOW()
             ORDER BY `id` DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':type' => $type]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Verwijder gebruikte challenge
        $stmt = $this->db->prepare(
            'DELETE FROM `webauthn_challenges` WHERE `user_id` = :uid AND `type` = :type'
        );
        $stmt->execute([':uid' => $userId, ':type' => $type]);

        return $row['challenge'];
    }

    /**
     * Publieke wrapper voor getUserCredentials — gebruikt door register-options
     * om te checken of user al een credential heeft
     */
    public function getUserCredentialsPublic(int $userId): array
    {
        return $this->getUserCredentials($userId);
    }

    private function getUserCredentials(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT `credential_id`, `public_key`, `counter`, `transports`
             FROM `user_credentials` WHERE `user_id` = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    private function credentialExists(string $credentialId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM `user_credentials` WHERE `credential_id` = :cid LIMIT 1'
        );
        $stmt->execute([':cid' => $credentialId]);
        return $stmt->fetch() !== false;
    }

    private function getCredentialById(string $credentialId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `user_credentials` WHERE `credential_id` = :cid LIMIT 1'
        );
        $stmt->execute([':cid' => $credentialId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function storeCredential(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `user_credentials`
             (`user_id`, `credential_id`, `public_key`, `counter`, `aaguid`, `transports`, `device_type`)
             VALUES (:uid, :cid, :pubkey, :counter, :aaguid, :transports, :device_type)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'],
            ':cid' => $data['credential_id'],
            ':pubkey' => $data['public_key'],
            ':counter' => $data['counter'],
            ':aaguid' => $data['aaguid'],
            ':transports' => $data['transports'],
            ':device_type' => 'singleDevice',
        ]);
    }

    private function updateCredentialCounter(string $credentialId, int $newCounter): void
    {
        $stmt = $this->db->prepare(
            'UPDATE `user_credentials` SET `counter` = :counter, `last_used_at` = NOW()
             WHERE `credential_id` = :cid'
        );
        $stmt->execute([
            ':counter' => $newCounter,
            ':cid' => $credentialId,
        ]);
    }

    // ======================================================================
    // UTILITY HELPERS
    // ======================================================================

    private function getRpId(): string
    {
        // Extraheren domein uit FULL_BASE_URL
        $parsed = parse_url(FULL_BASE_URL);
        return $parsed['host'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    private function validateOrigin(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        $allowedOrigin = rtrim(FULL_BASE_URL, '/');

        // Direct match
        if ($origin === $allowedOrigin) {
            return true;
        }

        // Accepteer ook HTTPS variant van HTTP origin (en vice versa)
        // Voorkomt "Invalid origin" als .env APP_URL http:// is maar site via https:// bereikbaar
        $originNoScheme = preg_replace('#^https?://#', '', $origin);
        $allowedNoScheme = preg_replace('#^https?://#', '', $allowedOrigin);

        return $originNoScheme === $allowedNoScheme;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($padded);
    }
}
