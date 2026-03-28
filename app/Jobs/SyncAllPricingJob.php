<?php

namespace App\Jobs;

use App\Enums\MarkupType;
use App\Models\Country;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\ExchangeRateService;
use App\Services\PricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncAllPricingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes

    public function __construct() {}

    public function handle(PricingService $pricingService, ExchangeRateService $exchangeRateService): void
    {
        $startTime = microtime(true);
        $startDate = now();

        Log::channel('activity')->info('SyncAllPricingJob started', [
            'started_at' => $startDate->toDateTimeString(),
        ]);
        
        Cache::put('sync_in_progress', true, 1800);

        try {
            // Step 1: Sync Exchange Rate
            try {
                $exchangeRateService->syncUsdToNgn();
            } catch (\Exception $e) {
                throw $e;
            }

            $baseUrl = rtrim(config('services.fivesim.base_url', 'https://5sim.net/v1'), '/');
            $apiKey = (string) config('services.fivesim.api_key', '');
            $syncedAt = now();

            $providerRequest = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout(300);

            if ($apiKey !== '') {
                $providerRequest = $providerRequest->withToken($apiKey);
            }

            // Step 2: Sync Countries
            Log::channel('activity')->info('📊 Step 2: Fetching countries from 5SIM API...');
            $countriesResponse = $providerRequest->get("{$baseUrl}/guest/countries");
            
            if (!$countriesResponse->successful()) {
                Log::channel('activity')->error("❌ Failed to fetch countries: HTTP {$countriesResponse->status()}");
                throw new \Exception("Failed to fetch countries from 5Sim API: HTTP {$countriesResponse->status()}");
            }

            $apiCountries = $countriesResponse->json();
            if (! is_array($apiCountries)) {
                throw new \RuntimeException('Invalid countries payload from 5SIM API');
            }

            $countrysFetched = count($apiCountries);

            $countryRows = [];
            $existingCountryCodesByProvider = Country::query()
                ->pluck('code', 'provider_code')
                ->mapWithKeys(fn ($code, $providerCode) => [strtolower((string) $providerCode) => $code ? strtoupper((string) $code) : null])
                ->all();
            $usedCountryCodes = array_fill_keys(
                Country::query()->whereNotNull('code')->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all(),
                true
            );

            foreach ($apiCountries as $slug => $data) {
                $slug = strtolower((string) $slug);
                $countryData = is_array($data) ? $data : [];
                $isoMap = is_array($countryData['iso'] ?? null) ? $countryData['iso'] : [];
                $prefixMap = is_array($countryData['prefix'] ?? null) ? $countryData['prefix'] : [];
                $isoCode = array_key_first($isoMap);
                $isoCode = $isoCode ? strtoupper((string) $isoCode) : null;

                $assignedCode = $existingCountryCodesByProvider[$slug] ?? null;
                if (! $assignedCode) {
                    $candidate = $isoCode ?: strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $slug), 0, 6)) . strtoupper(substr(md5($slug), 0, 4));
                    $candidate = strtoupper(substr($candidate, 0, 10));

                    if (isset($usedCountryCodes[$candidate])) {
                        $candidate = strtoupper(substr(md5($slug), 0, 10));
                    }

                    $assignedCode = $candidate;
                }

                $usedCountryCodes[$assignedCode] = true;

                $capabilities = [];
                foreach ($countryData as $key => $value) {
                    if (str_starts_with((string) $key, 'virtual')) {
                        $capabilities[$key] = $value;
                    }
                }

                $countryRows[] = [
                    'provider_code' => $slug,
                    'name' => (string) ($countryData['text_en'] ?? ucfirst($slug)),
                    'provider_name_ru' => isset($countryData['text_ru']) ? (string) $countryData['text_ru'] : null,
                    'code' => $assignedCode,
                    'provider_iso' => ! empty($isoMap) ? json_encode($isoMap, JSON_UNESCAPED_UNICODE) : null,
                    'provider_prefix' => ! empty($prefixMap) ? json_encode($prefixMap, JSON_UNESCAPED_UNICODE) : null,
                    'provider_capabilities' => ! empty($capabilities) ? json_encode($capabilities, JSON_UNESCAPED_UNICODE) : null,
                    'provider_payload' => json_encode($countryData, JSON_UNESCAPED_UNICODE),
                    'last_synced_at' => $syncedAt,
                    'is_active' => true,
                    'created_at' => $syncedAt,
                    'updated_at' => $syncedAt,
                ];
            }

            foreach (array_chunk($countryRows, 300) as $countryChunk) {
                Country::upsert(
                    $countryChunk,
                    ['provider_code'],
                    ['name', 'provider_name_ru', 'code', 'provider_iso', 'provider_prefix', 'provider_capabilities', 'provider_payload', 'last_synced_at', 'is_active', 'updated_at']
                );
            }

            $countryMap = Country::query()
                ->whereIn('provider_code', array_map('strtolower', array_keys($apiCountries)))
                ->get(['id', 'provider_code'])
                ->keyBy(fn (Country $country) => strtolower((string) $country->provider_code))
                ->all();


            $servicesResponse = $providerRequest->get("{$baseUrl}/guest/products/any/any");

            if (!$servicesResponse->successful()) {
                Log::channel('activity')->error("❌ Failed to fetch services: HTTP {$servicesResponse->status()}");
                throw new \Exception("Failed to fetch services from 5Sim API: HTTP {$servicesResponse->status()}");
            }

            $apiServices = $servicesResponse->json();
            if (! is_array($apiServices)) {
                throw new \RuntimeException('Invalid services payload from 5SIM API');
            }

            $servicesFetched = count($apiServices);

            $serviceRows = [];
            foreach ($apiServices as $slug => $data) {
                $slug = strtolower((string) $slug);
                $serviceData = is_array($data) ? $data : [];
                $serviceRows[] = [
                    'provider_service_code' => $slug,
                    'name' => ucwords(str_replace(['_', '-'], ' ', $slug)),
                    'slug' => $slug,
                    'provider_category' => isset($serviceData['Category']) ? (string) $serviceData['Category'] : null,
                    'provider_qty' => (int) ($serviceData['Qty'] ?? 0),
                    'provider_base_price' => isset($serviceData['Price']) ? (float) $serviceData['Price'] : null,
                    'provider_payload' => json_encode($serviceData, JSON_UNESCAPED_UNICODE),
                    'last_synced_at' => $syncedAt,
                    'is_active' => true,
                    'created_at' => $syncedAt,
                    'updated_at' => $syncedAt,
                ];
            }

            foreach (array_chunk($serviceRows, 250) as $serviceChunk) {
                Service::upsert(
                    $serviceChunk,
                    ['provider_service_code'],
                    ['name', 'slug', 'provider_category', 'provider_qty', 'provider_base_price', 'provider_payload', 'last_synced_at', 'is_active', 'updated_at']
                );
            }

            $serviceMap = Service::query()
                ->whereIn('provider_service_code', array_map('strtolower', array_keys($apiServices)))
                ->get(['id', 'provider_service_code'])
                ->keyBy(fn (Service $service) => strtolower($service->provider_service_code))
                ->all();


            $pricesResponse = $providerRequest
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get("{$baseUrl}/guest/prices");

            if (!$pricesResponse->successful()) {
                Log::channel('activity')->error("❌ Failed to fetch prices: HTTP {$pricesResponse->status()}");
                throw new \Exception("Failed to fetch prices from 5Sim API: HTTP {$pricesResponse->status()}");
            }

            $allPrices = $pricesResponse->json();
            if (! is_array($allPrices)) {
                throw new \RuntimeException('Invalid prices payload from 5SIM API');
            }

            $pricesFetched = count($allPrices);
            
            $existingPrices = ServicePrice::query()
                ->select(['service_id', 'country_id', 'markup_type', 'markup_value', 'is_active'])
                ->get()
                ->keyBy(fn (ServicePrice $price) => $price->service_id . '_' . $price->country_id);

            $syncedCount = 0;
            $skippedCount = 0;
            $priceCreated = 0;
            $priceUpdated = 0;
            $priceErrors = 0;
            $batchRows = [];
            $seenKeys = [];
            $seenServiceIds = [];

            foreach ($allPrices as $countryKey => $products) {
                $cKey = strtolower($countryKey);
                
                if (! isset($countryMap[$cKey])) {
                    $name = ucfirst($countryKey);
                    $fallbackCode = strtoupper(substr(md5((string) $countryKey), 0, 10));
                    
                    $country = Country::firstOrCreate(
                        ['provider_code' => $countryKey],
                        [
                            'name' => $name,
                            'provider_name_ru' => null,
                            'code' => $fallbackCode,
                            'is_active' => true,
                            'last_synced_at' => $syncedAt,
                        ]
                    );
                    $countryMap[$cKey] = $country;
                }
                
                $country = $countryMap[$cKey];

                foreach ($products as $serviceKey => $operators) {
                    try {
                        $sKey = strtolower($serviceKey);
                        
                        if (! isset($serviceMap[$sKey])) {
                            $name = ucwords(str_replace(['_', '-'], ' ', $serviceKey));
                            $service = Service::firstOrCreate(
                                ['provider_service_code' => $sKey],
                                [
                                    'name' => $name,
                                    'slug' => $sKey,
                                    'provider_qty' => 0,
                                    'is_active' => true,
                                    'last_synced_at' => $syncedAt,
                                ]
                            );
                            $serviceMap[$sKey] = $service;
                        }
                        
                        $service = $serviceMap[$sKey];

                        $lowestCost = null;
                        $availableCount = 0;
                        $operatorsCount = 0;
                        
                        if (is_array($operators)) {
                            $operatorsCount = count($operators);
                            foreach ($operators as $opName => $info) {
                                $count = (int) ($info['count'] ?? 0);
                                if ($count > 0) {
                                    $availableCount += $count;
                                }

                                if (isset($info['cost']) && $count > 0) {
                                    $cost = (float) $info['cost'];
                                    if ($lowestCost === null || $cost < $lowestCost) {
                                        $lowestCost = $cost;
                                    }
                                }
                            }
                        }

                        if ($lowestCost === null) {
                            $lowestCost = 0.0;
                        }

                        $pairKey = $service->id . '_' . $country->id;
                        $existing = $existingPrices->get($pairKey);
                        $markupTypeRaw = $existing ? $existing->markup_type : MarkupType::Fixed;
                        $markupTypeValue = $markupTypeRaw instanceof MarkupType
                            ? $markupTypeRaw->value
                            : (string) $markupTypeRaw;

                        try {
                            $markupType = MarkupType::from($markupTypeValue);
                        } catch (\ValueError) {
                            $markupType = MarkupType::Fixed;
                            $markupTypeValue = MarkupType::Fixed->value;
                        }

                        $markupValue = $existing ? (float) $existing->markup_value : 0.0;
                        $isActive = $existing ? (bool) $existing->is_active : true;
                        $finalPrice = $pricingService->calculateFinalPrice($lowestCost, $markupType, $markupValue);

                        $batchRows[] = [
                            'service_id' => $service->id,
                            'country_id' => $country->id,
                            'provider_price' => $lowestCost,
                            'available_count' => $availableCount,
                            'operator_count' => $operatorsCount,
                            'provider_payload' => json_encode($operators, JSON_UNESCAPED_UNICODE),
                            'last_seen_at' => $syncedAt,
                            'is_active' => $isActive,
                            'markup_type' => $markupTypeValue,
                            'markup_value' => $markupValue,
                            'final_price' => $finalPrice,
                            'created_at' => $syncedAt,
                            'updated_at' => $syncedAt,
                        ];

                        $seenKeys[$pairKey] = true;
                        $seenServiceIds[$service->id] = true;

                        if ($existing) {
                            $priceUpdated++;
                        } else {
                            $priceCreated++;
                        }

                        if ($availableCount <= 0) {
                            $skippedCount++;
                        }

                        $syncedCount++;

                        if (count($batchRows) >= 1000) {
                            ServicePrice::upsert(
                                $batchRows,
                                ['service_id', 'country_id'],
                                ['provider_price', 'available_count', 'operator_count', 'provider_payload', 'last_seen_at', 'is_active', 'markup_type', 'markup_value', 'final_price', 'updated_at']
                            );
                            $batchRows = [];
                        }
                    } catch (\Exception $e) {
                        $priceErrors++;
                    }
                }
            }

            if (! empty($batchRows)) {
                ServicePrice::upsert(
                    $batchRows,
                    ['service_id', 'country_id'],
                    ['provider_price', 'available_count', 'operator_count', 'provider_payload', 'last_seen_at', 'is_active', 'markup_type', 'markup_value', 'final_price', 'updated_at']
                );
            }

            $staleZeroed = 0;
            if (! empty($seenServiceIds)) {
                $staleZeroed = ServicePrice::query()
                    ->whereIn('service_id', array_keys($seenServiceIds))
                    ->where(function ($query) use ($syncedAt) {
                        $query->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', $syncedAt);
                    })
                    ->update([
                        'available_count' => 0,
                        'operator_count' => 0,
                        'provider_payload' => null,
                        'updated_at' => $syncedAt,
                    ]);
            }

            // Summary
            $durationSeconds = round(microtime(true) - $startTime, 2);
            $endDate = now();
            $totalErrors = $priceErrors;

            Log::channel('activity')->info('SyncAllPricingJob completed', [
                'started_at' => $startDate->toDateTimeString(),
                'finished_at' => $endDate->toDateTimeString(),
                'duration_seconds' => $durationSeconds,
                'countries_fetched' => $countrysFetched,
                'services_fetched' => $servicesFetched,
                'price_country_keys' => $pricesFetched,
                'prices_upserted' => $syncedCount,
                'price_created' => $priceCreated,
                'price_updated' => $priceUpdated,
                'inactive_stock_pairs' => $skippedCount,
                'stale_zeroed' => $staleZeroed,
                'errors' => $totalErrors,
            ]);

        } catch (\Throwable $e) {
            $durationSeconds = round(microtime(true) - $startTime, 2);

            Log::channel('activity')->error('SyncAllPricingJob failed', [
                'started_at' => $startDate->toDateTimeString(),
                'failed_at' => now()->toDateTimeString(),
                'duration_seconds' => $durationSeconds,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            Log::error('SYNC ALL PRICING JOB FAILED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        } finally {
            Cache::forget('sync_in_progress');
        }
    }
}
