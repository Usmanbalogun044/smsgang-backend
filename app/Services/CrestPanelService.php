<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrestPanelService
{
    private ?string $apiKey;
    private string $baseUrl = 'https://crestpanel.com/api/v2';

    public function __construct()
    {
        $this->apiKey = config('services.crestpanel.key');
        
        if (!$this->apiKey) {
            Log::warning('CrestPanel API key is not configured');
        }
    }

    /**
     * Get all services from CrestPanel
     */
    public function getServices(): array
    {
        try {
            $response = Http::post($this->baseUrl, [
                'key' => $this->apiKey,
                'action' => 'services',
            ]);

            if (!$response->successful()) {
                Log::error('CrestPanel getServices failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $services = $response->json();

            if (!is_array($services)) {
                Log::warning('CrestPanel services response is not array', [
                    'type' => gettype($services),
                ]);
                return [];
            }

            return $services;
        } catch (\Exception $e) {
            Log::error('CrestPanel getServices exception', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Create an order on CrestPanel
     */
    public function createOrder(array $data): ?array
    {
        try {
            $payload = [
                'key' => $this->apiKey,
                'action' => 'add',
                'service' => $data['service_id'],
                'link' => $data['link'],
                'quantity' => $data['quantity'],
            ];

            // Add optional fields
            if (!empty($data['runs'])) {
                $payload['runs'] = $data['runs'];
            }
            if (!empty($data['interval'])) {
                $payload['interval'] = $data['interval'];
            }
            if (!empty($data['comments'])) {
                $payload['comments'] = $data['comments'];
            }

            $response = Http::post($this->baseUrl, $payload);

            if (!$response->successful()) {
                Log::error('CrestPanel createOrder failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $result = $response->json();

            if (isset($result['error'])) {
                Log::warning('CrestPanel createOrder returned error', [
                    'error' => $result['error'],
                    'data' => $data,
                ]);
                return null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('CrestPanel createOrder exception', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return null;
        }
    }

    /**
     * Get order status from CrestPanel
     */
    public function getOrderStatus(string $crestpanelOrderId): ?array
    {
        try {
            $response = Http::post($this->baseUrl, [
                'key' => $this->apiKey,
                'action' => 'status',
                'order' => $crestpanelOrderId,
            ]);

            if (!$response->successful()) {
                Log::error('CrestPanel getOrderStatus failed', [
                    'status' => $response->status(),
                    'order_id' => $crestpanelOrderId,
                ]);
                return null;
            }

            $result = $response->json();

            if (isset($result['error'])) {
                Log::warning('CrestPanel getOrderStatus returned error', [
                    'error' => $result['error'],
                    'order_id' => $crestpanelOrderId,
                ]);
                return null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('CrestPanel getOrderStatus exception', [
                'error' => $e->getMessage(),
                'order_id' => $crestpanelOrderId,
            ]);
            return null;
        }
    }

    /**
     * Get CrestPanel account balance
     */
    public function getBalance(): ?float
    {
        try {
            // Try to get cached balance first
            $cached = Cache::get('crestpanel_balance');
            if ($cached !== null) {
                return $cached;
            }

            $response = Http::post($this->baseUrl, [
                'key' => $this->apiKey,
                'action' => 'balance',
            ]);

            if (!$response->successful()) {
                Log::error('CrestPanel getBalance failed', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $result = $response->json();

            if (isset($result['error'])) {
                Log::warning('CrestPanel getBalance returned error', [
                    'error' => $result['error'],
                ]);
                return null;
            }

            $balance = (float) ($result['balance'] ?? 0);

            // Cache balance for 10 minutes
            Cache::put('crestpanel_balance', $balance, now()->addMinutes(10));

            return $balance;
        } catch (\Exception $e) {
            Log::error('CrestPanel getBalance exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
