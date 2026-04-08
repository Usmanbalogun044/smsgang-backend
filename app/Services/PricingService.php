<?php

namespace App\Services;

use App\Enums\MarkupType;
use App\Models\Country;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Setting;
use App\Models\User;
use App\Models\VendorVirtualServiceMarkup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PricingService
{
    /**
     * Calculate final price in NGN with volatility protection.
     */
    public function calculateFinalPrice(
        float $providerPriceUsd,
        MarkupType $markupType,
        float $markupValue,
        ?User $user = null,
        ?Service $service = null,
        ?Country $country = null,
    ): float
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

        if ($user?->isVendor() && $service) {
            $vendorMarkup = VendorVirtualServiceMarkup::query()
                ->where('user_id', $user->id)
                ->where('service_id', $service->id)
                ->where('is_active', true)
                ->when(
                    $country,
                    fn ($query) => $query->where(function ($subQuery) use ($country) {
                        $subQuery->where('country_id', $country->id)
                            ->orWhereNull('country_id');
                    }),
                    fn ($query) => $query->whereNull('country_id')
                )
                ->orderByRaw('country_id is null')
                ->first();

            if ($vendorMarkup) {
                $vendorType = strtolower((string) $vendorMarkup->markup_type);
                $vendorValue = (float) $vendorMarkup->markup_value;

                $finalNgn = $vendorType === 'percent'
                    ? ($finalNgn * (1 + ($vendorValue / 100)))
                    : ($finalNgn + $vendorValue);
            } elseif ($user->vendor_virtual_markup_value !== null && $user->vendor_virtual_markup_type !== null) {
                $vendorType = strtolower((string) $user->vendor_virtual_markup_type);
                $vendorValue = (float) $user->vendor_virtual_markup_value;

                $finalNgn = $vendorType === 'percent'
                    ? ($finalNgn * (1 + ($vendorValue / 100)))
                    : ($finalNgn + $vendorValue);
            }

            $vendorGlobalMarkup = (float) Setting::get('vendor_global_markup_virtual', 0);
            $finalNgn += $vendorGlobalMarkup;
        }

        // 5. Standard rounding to 2 decimal places instead of aggressive 50 rounding
        return round(max(0, $finalNgn), 2);
    }

    /**
     * Calculate Twilio monthly subscription sell price in NGN.
     */
    public function calculateTwilioMonthlyPrice(float $providerPriceUsd): float
    {
        return $this->calculateTwilioMonthlyBreakdown($providerPriceUsd)['final_price_ngn'];
    }

    /**
     * Return full Twilio pricing breakdown for audit/profit persistence.
     */
    public function calculateTwilioMonthlyBreakdown(float $providerPriceUsd): array
    {
        $exchangeRate = (float) Setting::get('exchange_rate_usd_ngn', 1600.0);
        $effectiveExchangeRate = $exchangeRate * 1.05;

        $baseCostNgn = round($providerPriceUsd * $effectiveExchangeRate, 2);

        $twilioMarkupType = (string) Setting::get('twilio_markup_type', 'fixed');
        $twilioMarkupValue = (float) Setting::get('twilio_markup_value', 0);

        $finalPrice = $twilioMarkupType === 'percentage'
            ? round($baseCostNgn * (1 + ($twilioMarkupValue / 100)), 2)
            : round($baseCostNgn + $twilioMarkupValue, 2);

        return [
            'provider_price_usd' => round($providerPriceUsd, 4),
            'exchange_rate_used' => round($exchangeRate, 4),
            'effective_exchange_rate' => round($effectiveExchangeRate, 4),
            'global_markup_type_used' => null,
            'global_markup_value_used' => null,
            'twilio_markup_type_used' => $twilioMarkupType,
            'twilio_markup_value_used' => round($twilioMarkupValue, 4),
            'estimated_cost_ngn' => $baseCostNgn,
            'final_price_ngn' => $finalPrice,
            'profit_amount' => max(0, round($finalPrice - $baseCostNgn, 2)),
        ];
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
