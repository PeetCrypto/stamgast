<?php
declare(strict_types=1);

/**
 * FCM Client — Firebase Cloud Messaging HTTP v1 API
 *
 * Gebruikt de FCM HTTP v1 API met OAuth2 service account authenticatie.
 * De legacy API (server key + /fcm/send) is uitgeschakeld door Google.
 *
 * Token flow:
 * 1. Leest service account JSON (private_key, client_email, project_id)
 * 2. Bouwt + tekent een JWT (RS256)
 * 3. Wisselt JWT in voor een OAuth2 access token (cached 50 min)
 * 4. Gebruikt access token als Bearer in FCM v1 API calls
 */
class FCMClient
{
    private string $projectId;
    private string $serviceAccountPath;
    private ?string $accessToken   = null;
    private ?int    $tokenExpires  = null;
    private string  $tokenCachePath;

    // ── Constructor ──────────────────────────────────────────────

    public function __construct()
    {
        $this->serviceAccountPath = dirname(__DIR__) . '/config/regulr-vip-firebase-adminsdk-fbsvc-a78cf5314e.json';
        $this->tokenCachePath     = sys_get_temp_dir() . '/fcm_token_' . md5($this->serviceAccountPath) . '.cache';
        $this->projectId          = 'regulr-vip'; // default, wordt overschreven uit JSON
    }

    // ── Public: send message ─────────────────────────────────────

    /**
     * Send FCM notification to a single device token via v1 API.
     *
     * @param  string $token  FCM registration token
     * @param  string $title  Notification title
     * @param  string $body   Notification body
     * @param  array  $data   Optional data payload
     * @return string|false   JSON response string or false on failure
     */
    public function sendMessage(string $token, string $title, string $body, array $data = [])
    {
        // 1. Zorg dat we een geldig access token hebben
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            error_log('[FCM] Geen access token beschikbaar');
            return false;
        }

        // 2. Bouw v1 message payload
        $message = [
            'message' => [
                'token'        => $token,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
            ],
        ];

        // Data payload toevoegen als meegegeven
        if (!empty($data)) {
            $message['message']['data'] = $this->stringifyData($data);
        }

        // WebPush options voor klik-gedrag
        $message['message']['webpush'] = [
            'notification' => [
                'icon' => '/public/images/logo-192x192.png',
            ],
            'fcm_options' => [
                'link' => $this->getBaseUrl(),
            ],
        ];

        // 3. Verstuur via v1 API
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($message),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => !APP_DEBUG, // false in dev (Laragon CA issue), true in prod
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // 4. Foutafhandeling
        if ($curlErr) {
            error_log("[FCM] cURL error: {$curlErr}");
            return false;
        }

        if ($httpCode === 401) {
            // Token waarschijnlijk verlopen — clear cache voor volgende poging
            $this->invalidateToken();
            error_log('[FCM] 401 Unauthorized — access token ongeldig, cache geleegd');
            return false;
        }

        if ($httpCode >= 400) {
            $errData = json_decode($result, true);
            $errMsg  = $errData['error']['message'] ?? $result;
            $errSt   = $errData['error']['status'] ?? 'UNKNOWN';

            // UNREGISTERED token → log extra info
            if ($errSt === 'NOT_FOUND' || str_contains($errMsg, 'NotRegistered')) {
                error_log("[FCM] Token niet meer geregistreerd: " . substr($token, 0, 20) . "...");
            }

            error_log("[FCM] HTTP {$httpCode} ({$errSt}): {$errMsg}");
            return false;
        }

        return $result;
    }

    // ── Public: store token ──────────────────────────────────────

    /**
     * Store FCM token for a user (backward compat)
     */
    public function storeToken(int $userId, string $token): bool
    {
        global $db;

        try {
            $stmt = $db->prepare("UPDATE users SET fcm_token = :token WHERE id = :user_id");
            return $stmt->execute([
                ':token'   => $token,
                ':user_id' => $userId,
            ]);
        } catch (Exception $e) {
            error_log("[FCM] storeToken failed: " . $e->getMessage());
            return false;
        }
    }

    // ── Private: OAuth2 access token ─────────────────────────────

    /**
     * Haal een geldig OAuth2 access token op.
     * Gebruikt file-based cache (50 min TTL).
     */
    private function getAccessToken(): ?string
    {
        // Check memory cache
        if ($this->accessToken && $this->tokenExpires && time() < $this->tokenExpires) {
            return $this->accessToken;
        }

        // Check file cache (max 50 min oud = 3000 sec)
        if (file_exists($this->tokenCachePath) && (time() - filemtime($this->tokenCachePath)) < 3000) {
            $cached = json_decode(file_get_contents($this->tokenCachePath), true);
            if (!empty($cached['access_token'])) {
                $this->accessToken = $cached['access_token'];
                $this->tokenExpires = $cached['expires_at'] ?? time() + 3000;
                return $this->accessToken;
            }
        }

        // Vers token ophalen van Google
        return $this->fetchNewAccessToken();
    }

    /**
     * Haal een nieuw OAuth2 access token op via JWT signing.
     */
    private function fetchNewAccessToken(): ?string
    {
        // Service account JSON lezen
        if (!file_exists($this->serviceAccountPath)) {
            error_log('[FCM] Service account JSON niet gevonden: ' . $this->serviceAccountPath);
            return null;
        }

        $sa = json_decode(file_get_contents($this->serviceAccountPath), true);
        if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
            error_log('[FCM] Ongeldig service account JSON');
            return null;
        }

        $this->projectId = $sa['project_id'] ?? $this->projectId;

        // JWT bouwen
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $jwt = $this->buildJwt($header, $payload, $sa['private_key']);

        // Token request
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => !APP_DEBUG, // false in dev (Laragon CA issue), true in prod
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[FCM] Token request faalde (HTTP {$httpCode}): {$response}");
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            error_log('[FCM] Geen access_token in response: ' . $response);
            return null;
        }

        // Cache opslaan (file + memory)
        $cacheData = [
            'access_token' => $data['access_token'],
            'expires_at'   => time() + ($data['expires_in'] ?? 3600) - 120, // 2 min marge
        ];
        file_put_contents($this->tokenCachePath, json_encode($cacheData));

        $this->accessToken  = $data['access_token'];
        $this->tokenExpires = $cacheData['expires_at'];

        return $this->accessToken;
    }

    /**
     * Bouw en teken een JWT met RS256.
     */
    private function buildJwt(array $header, array $payload, string $privateKeyPem): string
    {
        $b64 = function (string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $h = $b64(json_encode($header));
        $p = $b64(json_encode($payload));
        $input = "{$h}.{$p}";

        $pk  = str_replace('\\n', "\n", $privateKeyPem);
        $sig = '';
        openssl_sign($input, $sig, $pk, OPENSSL_ALGO_SHA256);

        return "{$input}." . $b64($sig);
    }

    /**
     * Invalidate cached token (bijv. na 401).
     */
    private function invalidateToken(): void
    {
        $this->accessToken  = null;
        $this->tokenExpires = null;
        if (file_exists($this->tokenCachePath)) {
            @unlink($this->tokenCachePath);
        }
    }

    /**
     * Converteer data values naar strings (FCM vereist string values).
     */
    private function stringifyData(array $data): array
    {
        return array_map('strval', $data);
    }

    /**
     * Bepaal base URL voor push notification klik.
     */
    private function getBaseUrl(): string
    {
        $envUrl = getenv('APP_URL');
        if ($envUrl) {
            return rtrim($envUrl, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}";
    }
}
