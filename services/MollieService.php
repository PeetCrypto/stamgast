<?php
declare(strict_types=1);

/**
 * Exception thrown when a Mollie OAuth access token has expired or is invalid.
 * Callers should catch this and attempt a token refresh.
 */
class MollieTokenExpiredException extends \RuntimeException {}

/**
 * Mollie Service
 * API wrapper for Mollie payments with Connect (Marketplace) support
 *
 * Modes:
 * - mock:  simulates successful payments (no real API calls)
 * - test:  real Mollie API, test mode (sends testmode=true)
 * - live:  real Mollie API, live mode
 *
 * Connect support:
 * - onBehalfOf: payments created on behalf of connected tenant
 * - applicationFee: platform fee deducted automatically by Mollie
 *
 * IMPORTANT: When using Connect (onBehalfOf), the testmode parameter is
 * required for test payments. Mollie uses organization-level credentials
 * that need explicit testmode=true to create test payments.
 *
 * SECURITY: Platform API key only in server env, NEVER in database.
 * Tenant Mollie keys are DEPRECATED — all payments go through Mollie Connect.
 */

class MollieService
{
    private string $apiKey;
    private string $mode; // 'mock', 'test', 'live'
    private ?string $clientId;
    private ?string $clientSecret;
    private string $baseUrl = 'https://api.mollie.com/v2';

    public function __construct(string $apiKey, string $mode = 'mock', ?string $clientId = null, ?string $clientSecret = null)
    {
        $this->apiKey = $apiKey;
        $this->mode = $mode;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Create a payment (checkout) and return the redirect URL
     *
     * @param int $amountCents Amount in cents
     * @param string $description Payment description
     * @param string $redirectUrl Redirect after payment
     * @param string $webhookUrl Webhook URL for status updates
     * @param string $metadata Optional metadata (JSON string or plain text)
     * @param string|null $connectedOrganizationId Mollie Connect organization ID (onBehalfOf)
     * @param int $applicationFeeCents Platform fee in cents (deducted by Mollie)
     * @return array{payment_id: string, checkout_url: string}
     * @throws \RuntimeException on API failure
     */
    public function createPayment(
        int    $amountCents,
        string $description,
        string $redirectUrl,
        ?string $webhookUrl,
        string $metadata = '',
        ?string $connectedOrganizationId = null,
        int    $applicationFeeCents = 0,
        ?string $profileId = null
    ): array {
        // Mock mode: simulate a successful payment
        if ($this->mode === 'mock') {
            return $this->createMockPayment($amountCents, $description);
        }

        // Build payload
        $payload = [
            'amount' => [
                'currency' => 'EUR',
                'value'    => number_format($amountCents / 100, 2, '.', ''),
            ],
            'description' => $description,
            'redirectUrl' => $redirectUrl,
            'metadata'    => !empty($metadata) ? json_decode($metadata, true) : null,
        ];

        // webhookUrl is optional — omit for local dev (Mollie can't reach local URLs)
        if ($webhookUrl !== null) {
            $payload['webhookUrl'] = $webhookUrl;
        }

        // Remove null metadata — Mollie rejects null metadata
        if ($payload['metadata'] === null) {
            unset($payload['metadata']);
        } else {
            $payload['metadata'] = $metadata; // Send as raw string
        }

        // Website profile ID — required by Mollie for payment creation.
        // Without this, Mollie returns 422: "A website profile is required for payments."
        if ($profileId !== null && $profileId !== '') {
            $payload['profileId'] = $profileId;
        }

        // Test mode: required for Connect (organization-level credentials)
        // When using onBehalfOf with Connect, Mollie needs explicit testmode=true
        // to create test payments on the connected merchant's account.
        if ($this->mode === 'test') {
            $payload['testmode'] = true;
        }

        // Mollie Connect: create payment on behalf of tenant
        if ($connectedOrganizationId !== null && $connectedOrganizationId !== '') {
            $payload['onBehalfOf'] = $connectedOrganizationId;
        }

        // Platform fee: automatically deducted by Mollie
        if ($applicationFeeCents > 0) {
            $payload['applicationFee'] = [
                'amount' => [
                    'currency' => 'EUR',
                    'value'    => number_format($applicationFeeCents / 100, 2, '.', ''),
                ],
                'description' => 'REGULR.vip Platform Fee',
            ];
        }

        $response = $this->apiCall('POST', '/payments', $payload);

        if (!isset($response['id']) || !isset($response['_links']['checkout']['href'])) {
            throw new \RuntimeException('Mollie API error: missing payment ID or checkout URL');
        }

        return [
            'payment_id'   => $response['id'],
            'checkout_url' => $response['_links']['checkout']['href'],
        ];
    }

    /**
     * Get payment status from Mollie
     * Returns the FULL payment object for Connect fee extraction
     *
     * @return array{status: string, paid_at: string|null, amount_cents: int, application_fee_cents: int, mollie_fee_cents: int}
     * @throws \RuntimeException on API failure
     */
    public function getPaymentStatus(string $paymentId): array
    {
        // Mock mode: simulate paid status
        if ($this->mode === 'mock') {
            return $this->getMockPaymentStatus($paymentId);
        }

        // Request settlements embedded to get Mollie's fee
        // Test mode: must include testmode=true for Connect test payments,
        // otherwise Mollie returns 404 (looks in live namespace instead of test)
        $testmode = ($this->mode === 'test') ? '&testmode=true' : '';
        $response = $this->apiCall('GET', "/payments/{$paymentId}?embed=settlements{$testmode}");

        $amountCents = 0;
        if (isset($response['amount']['value'])) {
            $amountCents = (int) round(((float) $response['amount']['value']) * 100);
        }

        // Extract applicationFee from Mollie Connect response (THE TRUTH)
        // ⚠️ NEVER recalculate — use Mollie's authoritative value
        // Try _embedded first (documented path), then top-level fallback
        $applicationFeeCents = 0;
        if (isset($response['_embedded']['applicationFee']['amount']['value'])) {
            $applicationFeeCents = (int) round(
                (float) $response['_embedded']['applicationFee']['amount']['value'] * 100
            );
        } elseif (isset($response['applicationFee']['amount']['value'])) {
            $applicationFeeCents = (int) round(
                (float) $response['applicationFee']['amount']['value'] * 100
            );
        }

        // Extract Mollie's own transaction costs (from settlement)
        $mollieFeeCents = 0;
        if (isset($response['_embedded']['settlements'][0]['amount']['value'])) {
            // Settlement amount can be negative (costs)
            $settlementValue = (float) $response['_embedded']['settlements'][0]['amount']['value'];
            $mollieFeeCents = (int) round(abs($settlementValue) * 100);
        }

        return [
            'status'               => $response['status'] ?? 'unknown',
            'paid_at'              => $response['paidAt'] ?? null,
            'amount_cents'         => $amountCents,
            'application_fee_cents' => $applicationFeeCents,
            'mollie_fee_cents'     => $mollieFeeCents,
        ];
    }

    /**
     * Check if a payment status means "paid"
     */
    public function isPaid(string $status): bool
    {
        return $status === 'paid';
    }

    /**
     * Create a Mollie Connect OAuth authorization URL
     * Used for tenant onboarding
     *
     * @return string The URL to redirect the tenant to
     */
    public function getConnectAuthorizationUrl(string $redirectUri, string $state): string
    {
        $clientId = $this->clientId ?? MOLLIE_CONNECT_CLIENT_ID;
        if (empty($clientId)) {
            throw new \RuntimeException('MOLLIE_CONNECT_CLIENT_ID is not configured');
        }

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'organizations.read payments.read payments.write profiles.read',
            'state'         => $state,
        ]);

        return 'https://my.mollie.com/oauth2/authorize?' . $params;
    }

    /**
     * Exchange authorization code for access token (Connect onboarding)
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string, organization_id: string}
     * @throws \RuntimeException on failure
     */
    public function exchangeConnectCode(string $code, string $redirectUri): array
    {
        $clientId     = $this->clientId ?? MOLLIE_CONNECT_CLIENT_ID;
        $clientSecret = $this->clientSecret ?? MOLLIE_CONNECT_CLIENT_SECRET;

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Mollie Connect OAuth credentials not configured');
        }

        $ch = curl_init('https://api.mollie.com/oauth2/tokens');
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Mollie OAuth cURL error: ' . $curlError);
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode >= 400 || !isset($data['access_token'])) {
            $error = $data['error'] ?? $data['error_description'] ?? 'Unknown OAuth error';
            throw new \RuntimeException("Mollie OAuth error: {$error}");
        }

        // Fetch organization ID using the access token.
        // Mollie does NOT always return resource_owner_id in the token response.
        // We must call /v2/organizations/me to get the real organization ID.
        $orgId = '';
        $accessToken = $data['access_token'];

        $ch = curl_init('https://api.mollie.com/v2/organizations/me');
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                ],
            ]);

            $orgResponse = curl_exec($ch);
            $orgHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($orgResponse !== false && $orgHttpCode < 400) {
                $orgData = json_decode($orgResponse, true);
                if (isset($orgData['id'])) {
                    $orgId = $orgData['id'];
                }
            }
        }

        // Fallback: try resource_owner_id from token response
        if (empty($orgId) && isset($data['resource_owner_id'])) {
            $orgId = $data['resource_owner_id'];
        }

        // Mollie returns expires_in (seconds), not expires_at.
        // Calculate expires_at as now + expires_in.
        $expiresAt = '';
        if (isset($data['expires_in']) && is_numeric($data['expires_in'])) {
            $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
                ->modify('+' . (int) $data['expires_in'] . ' seconds')
                ->format('Y-m-d H:i:s');
        }

        return [
            'access_token'    => $accessToken,
            'refresh_token'   => $data['refresh_token'] ?? '',
            'expires_at'      => $expiresAt,
            'organization_id' => $orgId,
        ];
    }

    /**
     * Refresh an expired Mollie Connect OAuth access token.
     *
     * @param string $refreshToken The refresh token from the original OAuth exchange
     * @return array{access_token: string, refresh_token: string, expires_at: string}
     * @throws \RuntimeException on failure
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $clientId     = $this->clientId ?? MOLLIE_CONNECT_CLIENT_ID;
        $clientSecret = $this->clientSecret ?? MOLLIE_CONNECT_CLIENT_SECRET;

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Mollie Connect OAuth credentials not configured');
        }

        if (empty($refreshToken)) {
            throw new \RuntimeException('No refresh token available — manual re-authorization required');
        }

        $ch = curl_init('https://api.mollie.com/oauth2/tokens');
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => !$this->isLocalDev(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $caBundle = $this->getCaBundle();
        if ($caBundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Mollie OAuth refresh cURL error: ' . $curlError);
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode >= 400 || !isset($data['access_token'])) {
            $error = $data['error'] ?? $data['error_description'] ?? 'Unknown OAuth refresh error';
            throw new \RuntimeException("Mollie OAuth refresh error: {$error}");
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at'    => (isset($data['expires_in']) && is_numeric($data['expires_in']))
                ? (new DateTime('now', new DateTimeZone('UTC')))
                    ->modify('+' . (int) $data['expires_in'] . ' seconds')
                    ->format('Y-m-d H:i:s')
                : '',
        ];
    }

    /**
     * Create a mock payment (no real API call)
     */
    private function createMockPayment(int $amountCents, string $description): array
    {
        $mockPaymentId = 'mock_' . bin2hex(random_bytes(8));

        // In mock mode, redirect to the wallet page directly
        // The webhook will auto-simulate a "paid" status
        return [
            'payment_id'   => $mockPaymentId,
            'checkout_url' => '/wallet?mock_payment=' . $mockPaymentId . '&amount=' . $amountCents,
        ];
    }

    /**
     * Get mock payment status (always "paid" for testing)
     */
    private function getMockPaymentStatus(string $paymentId): array
    {
        // All mock payments are "paid" immediately
        // In mock mode, no application fee data is available
        return [
            'status'                => 'paid',
            'paid_at'               => date('Y-m-d\TH:i:sP'),
            'amount_cents'          => 0, // Will be determined from DB
            'application_fee_cents' => 0, // No fee in mock mode
            'mollie_fee_cents'      => 0,
        ];
    }

    /**
     * Make an API call to Mollie
     *
     * @throws \RuntimeException on cURL or API error
     * @throws \MollieTokenExpiredException when the access token is expired/invalid (HTTP 401)
     */
    private function apiCall(string $method, string $endpoint, array $payload = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => !$this->isLocalDev(),
        ];

        $caBundle = $this->getCaBundle();
        if ($caBundle && $curlOptions[CURLOPT_SSL_VERIFYPEER]) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOptions);

        if ($method === 'POST' && !empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Mollie cURL error: ' . $curlError);
        }

        if ($response === false) {
            throw new \RuntimeException('Mollie API: empty response');
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode === 401) {
            throw new \MollieTokenExpiredException('Mollie access token expired or invalid');
        }

        if ($httpCode >= 400) {
            $errorMsg = $data['detail'] ?? $data['title'] ?? 'Unknown Mollie error';
            throw new \RuntimeException("Mollie API error ({$httpCode}): {$errorMsg}");
        }

        return $data;
    }

    /**
     * Check if running in local development environment.
     * In local dev, SSL verification may fail due to incomplete CA bundles (e.g. Laragon).
     */
    private function isLocalDev(): bool
    {
        // CLI always counts as local dev (no HTTP_HOST, often incomplete CA bundle)
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return str_contains($host, '.test')
            || str_contains($host, '.local')
            || str_contains($host, 'localhost')
            || $host === '127.0.0.1';
    }

    /**
     * Get the CA bundle path for SSL verification.
     * Falls back to the PHP installation's cacert.pem if available.
     */
    private function getCaBundle(): ?string
    {
        // Check common locations for the CA bundle
        $candidates = [
            // PHP's configured curl.cainfo
            ini_get('curl.cainfo') ?: '',
            // PHP's configured openssl.cafile
            ini_get('openssl.cafile') ?: '',
            // Common Laragon location (same dir as php.exe)
            dirname(PHP_BINARY) . '/cacert.pem',
            // Common Laragon extras location
            dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
        ];

        foreach ($candidates as $path) {
            if (!empty($path) && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
