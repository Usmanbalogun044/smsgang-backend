<?php

namespace App\Services\SmsProviders;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FiveSimProvider implements ProviderInterface
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.fivesim.base_url'), '/');
        $this->apiKey = config('services.fivesim.api_key');
    }

    public function buyNumber(string $product, string $country, ?string $operator = null): array
    {
        $resolvedOperator = $operator ?: 'any';

        $response = $this->request()
            ->get("{$this->baseUrl}/user/buy/activation/{$country}/{$resolvedOperator}/{$product}");

        $response->throw();

        $data = $response->json();

        return [
            'id' => (string) $data['id'],
            'phone' => $data['phone'],
            'operator' => $data['operator'] ?? null,
            'price' => isset($data['price']) ? (float) $data['price'] : null,
            'cost' => isset($data['cost']) ? (float) $data['cost'] : null,
            'expires_at' => $data['expires'] ?? null,
        ];
    }

    public function checkSms(string $activationId): ?array
    {
        $response = $this->request()
            ->get("{$this->baseUrl}/user/check/{$activationId}");

        $response->throw();

        $data = $response->json();
        $sms = $data['sms'] ?? [];

        if (empty($sms)) {
            return null;
        }

        $codes = collect($sms)->pluck('code')->filter()->values()->toArray();

        return [
            'status' => $data['status'],
            'sms' => $sms,
            'codes' => $codes,
        ];
    }

    public function finishActivation(string $activationId): bool
    {
        try {
            $response = $this->request()
                ->get("{$this->baseUrl}/user/finish/{$activationId}");

            $response->throw();

            return true;
        } catch (RequestException $e) {
            Log::error('5SIM finish activation failed', [
                'activation_id' => $activationId,
                'status' => $e->response->status(),
            ]);

            return false;
        }
    }

    public function cancelActivation(string $activationId): bool
    {
        try {
            $response = $this->request()
                ->get("{$this->baseUrl}/user/cancel/{$activationId}");

            $response->throw();

            return true;
        } catch (RequestException $e) {
            Log::error('5SIM cancel activation failed', [
                'activation_id' => $activationId,
                'status' => $e->response->status(),
            ]);

            return false;
        }
    }

    public function getBalance(): float
    {
        $response = $this->request()
            ->get("{$this->baseUrl}/user/profile");

        $response->throw();

        return (float) $response->json('balance');
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->timeout(15)
            ->acceptJson();
    }
}
