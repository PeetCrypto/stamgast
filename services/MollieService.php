<?php
declare(strict_types=1);

/**
 * Mollie Service
 * API wrapper for Mollie payments with mock/test/live modes
 * Mock mode: simulates successful payments (no real API calls)
 * Test/Live mode: calls real Mollie API
 */

class MollieService
{
    private string $apiKey;
    private string $mode; // 'mock', 'test', 'live'
    private string $baseUrl = 'https://api.mollie.com/v2';

    public function __construct(string $apiKey, string $mode = 'mock')
    {
        $this->apiKey = $apiKey;
        $this->mode = $mode;
    }

    /**
     * Create a payment (checkout) and return the redirect URL
     *
     * @return array{payment_id: string, checkout_url: string}
     * @throws \RuntimeException on API failure
     */
    public function createPayment(
        int    $amountCents,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        string $metadata = ''
    ): array {
        // Mock mode: simulate a successful payment
        if ($this->mode === 'mock') {
            return $this->createMockPayment($amountCents, $description);
        }

        // Real Mollie API call
        $payload = [
            'amount' => [
                'currency' => 'EUR',
                'value'    => number_format($amountCents / 100, 2, '.', ''),
            ],
            'description' => $description,
            'redirectUrl' => $redirectUrl,
            'webhookUrl'  => $webhookUrl,
            'metadata'    => $metadata,
        ];

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
     *
     * @return array{status: string, paid_at: string|null, amount_cents: int}
     * @throws \RuntimeException on API failure
     */
    public function getPaymentStatus(string $paymentId): array
    {
        // Mock mode: simulate paid status
        if ($this->mode === 'mock') {
            return $this->getMockPaymentStatus($paymentId);
        }

        $response = $this->apiCall('GET', "/payments/{$paymentId}");

        $amountCents = 0;
        if (isset($response['amount']['value'])) {
            $amountCents = (int) round(((float) $response['amount']['value']) * 100);
        }

        return [
            'status'       => $response['status'] ?? 'unknown',
            'paid_at'      => $response['paidAt'] ?? null,
            'amount_cents' => $amountCents,
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
        return [
            'status'       => 'paid',
            'paid_at'      => date('Y-m-d\TH:i:sP'),
            'amount_cents' => 0, // Will be determined from DB
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
