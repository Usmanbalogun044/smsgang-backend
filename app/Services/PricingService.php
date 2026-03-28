<?php

namespace App\Services;

use App\Enums\MarkupType;
use App\Models\Country;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PricingService
{
    /**
     * Calculate final price in NGN with volatility protection.
     */
    public function calculateFinalPrice(float $providerPriceUsd, MarkupType $markupType, float $markupValue): float
    {
        // 1. Fetch settings from DB with optimized defaults
        $globalMarkup = (float) Setting::get('global_markup_fixed', 150);
        $globalMarkupType = Setting::get('global_markup_type', 'fixed'); // 'percentage' or 'fixed'
        $exchangeRate = (float) Setting::get('exchange_rate_usd_ngn', 1600.0);
        
        // Safety Buffer: Add 5% to the exchange rate to cover bank fees / USD volatility
        $safetyExchangeRate = $exchangeRate * 1.05;

        // 2. Convert provider price (USD) to NGN using safety rate
        $baseNgn = $providerPriceUsd * $safetyExchangeRate;

        // 3. Add global markup (Base profit)
        if ($globalMarkupType === 'percentage') {
             $baseNgn = $baseNgn * (1 + ($globalMarkup / 100));
        } else {
             $baseNgn += $globalMarkup;
        }

        // 4. Add individual service markup
        $finalNgn = match ($markupType) {
            MarkupType::Fixed => $baseNgn + $markupValue,
            MarkupType::Percent => $baseNgn * (1 + $markupValue / 100),
            // Handle string cases if enum casting fails/legacy data
            'fixed' => $baseNgn + $markupValue,
            'percentage' => $baseNgn * (1 + $markupValue / 100),
            default => $baseNgn + $markupValue,
        };

        // 5. Standard rounding to 2 decimal places instead of aggressive 50 rounding
        return round($finalNgn, 2);
    }

    public function syncPricesFromProvider(Country $country, Service $service): void
    {
        try {
            $baseUrl = rtrim(config('services.fivesim.base_url'), '/');
            $response = Http::withToken(config('services.fivesim.api_key'))
                ->timeout(15)
                ->get("{$baseUrl}/guest/prices", [
                    'country' => $country->code,
                    'product' => $service->provider_service_code,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $price = $this->extractLowestPrice($data, $country->code, $service->provider_service_code);

                if ($price !== null) {
                    $existing = ServicePrice::where('service_id', $service->id)
                        ->where('country_id', $country->id)
                        ->first();

                    $mType = $existing ? ($existing->markup_type instanceof MarkupType ? $existing->markup_type : MarkupType::from($existing->markup_type)) : MarkupType::Fixed;
                    $mVal = $existing ? (float)$existing->markup_value : 0;

                    ServicePrice::updateOrCreate(
                        ['service_id' => $service->id, 'country_id' => $country->id],
                        [
                            'provider_price' => $price,
                            'final_price' => $this->calculateFinalPrice($price, $mType, $mVal),
                            'is_active' => true
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error('Single sync failed: ' . $e->getMessage());
        }
    }

    private function extractLowestPrice(array $data, string $countryCode, string $product): ?float
    {
        $countryData = $data[strtolower($countryCode)] ?? null;
        if (!$countryData) return null;
        
        $productData = $countryData[strtolower($product)] ?? null;
        if (!$productData || !is_array($productData)) return null;

        $lowest = null;
        foreach ($productData as $op) {
            if (isset($op['cost'])) {
                $cost = (float)$op['cost'];
                if ($lowest === null || $cost < $lowest) $lowest = $cost;
            }
        }
        return $lowest;
    }
}
