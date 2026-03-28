<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * Pull latest USD->NGN from configured provider and persist to settings.
     */
    public function syncUsdToNgn(): ?float
    {
        Log::info('USD to NGN exchange rate sync started.');

        $baseUrl = rtrim((string) config('services.currency_api.base_url', ''), '/');
        $convertPath = (string) config('services.currency_api.convert_path', '/convert');
        $apiKey = (string) config('services.currency_api.api_key', '');
        $apiHost = (string) config('services.currency_api.host', '');
        $from = (string) config('services.currency_api.from', 'USD');
        $to = (string) config('services.currency_api.to', 'NGN');
        $amount = (float) config('services.currency_api.amount', 1);

        if ($baseUrl === '' || $apiKey === '') {
            Log::warning('Exchange rate sync skipped: currency API is not configured.', [
                'has_base_url' => $baseUrl !== '',
                'has_api_key' => $apiKey !== '',
            ]);
            return null;
        }

        if ($amount <= 0) {
            $amount = 1;
        }

        $headers = [
            'X-RapidAPI-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($apiHost !== '') {
            $headers['X-RapidAPI-Host'] = $apiHost;
        }

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => (int) config('services.currency_api.timeout', 15),
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        try {
            $response = $client->request('GET', $convertPath, [
                'headers' => $headers,
                'query' => [
                    'from' => strtoupper($from),
                    'to' => strtoupper($to),
                    'amount' => $amount,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('Exchange rate sync failed: request exception.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            Log::error('Exchange rate sync failed.', [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ]);

            return null;
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            Log::error('Exchange rate sync failed: invalid JSON payload.');
            return null;
        }

        if (($payload['success'] ?? false) !== true) {
            Log::error('Exchange rate sync failed: provider returned success=false.', [
                'payload' => $payload,
            ]);

            return null;
        }

        $queryFrom = strtoupper((string) data_get($payload, 'query.from', $from));
        $queryTo = strtoupper((string) data_get($payload, 'query.to', $to));
        if ($queryFrom !== strtoupper($from) || $queryTo !== strtoupper($to)) {
            Log::error('Exchange rate sync failed: unexpected currency pair in response.', [
                'expected' => strtoupper($from) . '->' . strtoupper($to),
                'received' => $queryFrom . '->' . $queryTo,
            ]);

            return null;
        }

        $rate = $this->extractRate($payload, $amount);
        if ($rate === null || $rate <= 0) {
            Log::error('Exchange rate sync failed: rate missing in response.', [
                'payload' => $payload,
            ]);

            return null;
        }

        $normalizedRate = round($rate, 4);

        Setting::set('exchange_rate_usd_ngn', $normalizedRate);
        Setting::set('exchange_rate_last_synced_at', now()->toDateTimeString());
        Setting::set('exchange_rate_source', 'rapidapi:convert');

        $providerDate = (string) data_get($payload, 'date', '');
        if ($providerDate !== '') {
            Setting::set('exchange_rate_provider_date', $providerDate);
        }

        $providerTimestamp = data_get($payload, 'info.timestamp');
        if (is_numeric($providerTimestamp)) {
            Setting::set('exchange_rate_provider_timestamp', (string) $providerTimestamp);
        }

        Log::info('Exchange rate synced successfully.', [
            'usd_ngn' => $normalizedRate,
            'source' => 'rapidapi:convert',
        ]);

        return $normalizedRate;
    }

    /**
     * Support common payload shapes from currency APIs.
     */
    private function extractRate(array $payload, float $amount): ?float
    {
        $candidates = [
            data_get($payload, 'info.rate'),
            data_get($payload, 'rates.NGN'),
            data_get($payload, 'data.rates.NGN'),
            data_get($payload, 'data.NGN'),
            data_get($payload, 'result.NGN'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        $result = data_get($payload, 'result');
        if (is_numeric($result)) {
            $amount = $amount > 0 ? $amount : 1;
            return (float) $result / $amount;
        }

        return null;
    }
}
