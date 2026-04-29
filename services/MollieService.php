<?php
declare(strict_types=1);

/**
 * Mollie Service
 * API wrapper for Mollie payments with Connect (Marketplace) support
 *
 * Modes:
 * - mock:  simulates successful payments (no real API calls)
 * - test:  real Mollie API, test keys
 * - live:  real Mollie API, live keys
 *
 * Connect support:
 * - onBehalfOf: payments created on behalf of connected tenant
 * - applicationFee: platform fee deducted automatically by Mollie
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
        string $webhookUrl,
        string $metadata = '',
        ?string $connectedOrganizationId = null,
        int    $applicationFeeCents = 0
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
            'webhookUrl'  => $webhookUrl,
            'metadata'    => !empty($metadata) ? json_decode($metadata, true) : null,
        ];

        // Remove null metadata — Mollie rejects null metadata
        if ($payload['metadata'] === null) {
            unset($payload['metadata']);
        } else {
            $payload['metadata'] = $metadata; // Send as raw string
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
        $response = $this->apiCall('GET', "/payments/{$paymentId}?embed=settlements");

        $amountCents = 0;
        if (isset($response['amount']['value'])) {
            $amountCents = (int) round(((float) $response['amount']['value']) * 100);
        }

        // Extract applicationFee from Mollie Connect response (THE TRUTH)
        // ⚠️ NEVER recalculate — use Mollie's authoritative value
        $applicationFeeCents = 0;
        if (isset($response['_embedded']['applicationFee']['amount']['value'])) {
            $applicationFeeCents = (int) round(
                (float) $response['_embedded']['applicationFee']['amount']['value'] * 100
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
            'scope'         => 'organizations.read payments.read',
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

        // Fetch organization details to get the organization ID
        $orgId = '';
        if (isset($data['resource_owner_id'])) {
            $orgId = $data['resource_owner_id'];
        }

        return [
            'access_token'   => $data['access_token'],
            'refresh_token'  => $data['refresh_token'] ?? '',
            'expires_at'     => $data['expires_at'] ?? '',
            'organization_id' => $orgId,
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

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

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

        if ($httpCode >= 400) {
            $errorMsg = $data['detail'] ?? $data['title'] ?? 'Unknown Mollie error';
            throw new \RuntimeException("Mollie API error ({$httpCode}): {$errorMsg}");
        }

        return $data;
    }
}
