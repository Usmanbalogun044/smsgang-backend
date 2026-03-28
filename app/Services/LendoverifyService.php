<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LendoverifyService
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.lendoverify.base_url'), '/');
        $this->apiKey = config('services.lendoverify.api_key');
    }

    /**
     * Initialize a payment / Gateway Top-up
     *
     * @param  array  $data  ['amount', 'customerEmail', 'customerName', 'paymentReference', 'paymentDescription', 'redirectUrl']
     */
    public function initializeTransaction(array $data): array
    {
        $redirectUrl = $data['redirectUrl'] ?? config('app.verify_payment_url', config('app.url'));

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'accept' => 'application/json',
            ])->timeout(30)->retry(2, 100)->asForm()->post("{$this->baseUrl}/api/customers/transaction/initialize", [
                'customerEmail' => $data['customerEmail'],
                'customerName' => $data['customerName'],
                'amount' => $data['amount'],
                'paymentReference' => $data['paymentReference'] ?? 'SMS_' . uniqid(),
                'paymentDescription' => $data['paymentDescription'] ?? 'SMS Activation',
                'redirectUrl' => $redirectUrl,
            ]);

            if ($response->successful()) {
                $payload = $response->json() ?? [];
                $normalized = $payload['data'] ?? $payload;

                $checkoutUrl = $normalized['checkout_url']
                    ?? $normalized['checkoutUrl']
                    ?? $normalized['authorization_url']
                    ?? $normalized['authorizationUrl']
                    ?? $normalized['payment_url']
                    ?? $normalized['paymentUrl']
                    ?? $normalized['link']
                    ?? null;

                if ($checkoutUrl) {
                    $normalized['checkout_url'] = $checkoutUrl;
                }

                return [
                    'data' => $normalized,
                    'raw' => $payload,
                ];
            }

            Log::error('Lendoverify Transaction Initialization Failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'data' => $data,
            ]);

            throw new \Exception('Failed to initialize transaction: ' . ($response->json()['message'] ?? 'Unknown error'));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Lendoverify Connection Error', ['message' => $e->getMessage()]);
            throw new \Exception('Unable to connect to payment gateway. Please try again later.');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('Lendoverify Request Error', ['message' => $e->getMessage()]);
            throw new \Exception('Payment gateway error. Please try again later.');
        } catch (\Exception $e) {
            Log::error('Lendoverify API Exception', ['message' => $e->getMessage()]);
            throw new \Exception('Failed to initialize payment. Please try again.');
        }
    }

    /**
     * Verify a transaction reference
     */
    public function verifyReference(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'accept' => 'application/json',
            ])->asForm()->post("{$this->baseUrl}/api/customers/transaction/verify", [
                'reference' => $reference,
            ]);

            $data = $response->json();

            Log::info('Lendoverify Verification Raw:', ['body' => $response->body()]);
            Log::info('Lendoverify Verification JSON:', ['json' => $data]);

            if ($response->successful()) {
                return $data;
            }

            Log::error('Lendoverify Transaction Verification Failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'reference' => $reference,
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('Lendoverify Verification Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
