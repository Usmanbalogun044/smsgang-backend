<?php

namespace App\Services;

use App\Models\SmmService;
use App\Models\SmmServicePrice;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class SmmPricingService
{
    public function __construct()
    {
        // No-op constructor kept for DI compatibility.
    }

    /**
     * Calculate final price for SMM service (CrestPanel rate is for 1000 units, already in NGN)
     */
    public function calculatePrice(SmmService $service, int $quantity = 1): array
    {
        $quantity = max(1, $quantity);

        // CrestPanel rate is for 1,000 units. Convert to price per 1 unit.
        $rateInNgnPer1000 = (float) $service->rate;
        $pricePerUnit = $rateInNgnPer1000 / 1000;
        
        // Cost of the request before any markup
        $totalCostNgn = $pricePerUnit * $quantity;

        // Resolve markup: per-service override first, then global settings.
        $markup = $this->resolveMarkupForService($service);
        $markupValue = (float) $markup['value'];
        $markupType = (string) $markup['type'];

        $finalPriceNgn = $totalCostNgn;

        if ($markupType === 'percent') {
            $finalPriceNgn = $totalCostNgn * (1 + ($markupValue / 100));
        } else {
            // Fixed markup per 1,000 units (scaled to requested quantity)
            $fixedMarkupPerUnit = $markupValue / 1000;
            $finalPriceNgn = $totalCostNgn + ($fixedMarkupPerUnit * $quantity);
        }

        // Calculate profit
        $profit = max(0, $finalPriceNgn - $totalCostNgn);

        $finalPriceRounded = round($finalPriceNgn, 2);
        $costPriceRounded = round($totalCostNgn, 2);
        $profitRounded = round($profit, 2);
        $ratePerUnit = round($finalPriceNgn / $quantity, 4);

        return [
            'total_price' => $finalPriceRounded,
            'final_price_ngn' => $finalPriceRounded,
            'cost_price' => $costPriceRounded,
            'profit' => $profitRounded,
            'rate_per_unit' => $ratePerUnit,
            'final_price_per_unit' => $ratePerUnit,
            'final_price_per_1000' => round($ratePerUnit * 1000, 2),
            'markup_type' => $markupType,
            'markup_value' => $markupValue,
            'markup_source' => $markup['source'],
            'quantity' => $quantity,
            'base_rate_per_1000' => $rateInNgnPer1000,
            'currency' => 'NGN'
        ];
    }

    /**
     * Resolve effective markup for a service.
     */
    public function resolveMarkupForService(SmmService $service): array
    {
        $servicePrice = SmmServicePrice::where('smm_service_id', $service->id)
            ->where('is_active', true)
            ->first();

        if ($servicePrice && $servicePrice->markup_value !== null) {
            $type = strtolower((string) $servicePrice->markup_type);
            return [
                'type' => $type === 'percent' ? 'percent' : 'fixed',
                'value' => (float) $servicePrice->markup_value,
                'source' => 'service',
            ];
        }

        $globalMarkupValue = (float) Setting::get('smm_global_markup_fixed', 500);
        $globalMarkupType = strtolower((string) Setting::get('smm_global_markup_type', 'fixed'));

        return [
            'type' => $globalMarkupType === 'percent' ? 'percent' : 'fixed',
            'value' => $globalMarkupValue,
            'source' => 'global',
        ];
    }

    /**
     * Sync and update service prices from CrestPanel
     */
    public function syncServices(array $crestpanelServices): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        foreach ($crestpanelServices as $cpService) {
            try {
                $service = SmmService::updateOrCreate(
                    ['crestpanel_service_id' => $cpService['service'] ?? null],
                    [
                        'name' => $cpService['name'] ?? 'Unknown',
                        'category' => $cpService['category'] ?? null,
                        'type' => $cpService['type'] ?? null,
                        'rate' => (float) ($cpService['rate'] ?? 0), // Rate is in NGN from CrestPanel
                        'min' => (int) ($cpService['min'] ?? 1),
                        'max' => (int) ($cpService['max'] ?? 10000),
                        'refill' => (bool) ($cpService['refill'] ?? false),
                        'cancel' => (bool) ($cpService['cancel'] ?? false),
                        'provider_payload' => $cpService,
                        'last_synced_at' => now(),
                        'is_active' => true,
                    ]
                );

                // Calculate price per unit (quantity = 1)
                $priceData = $this->calculatePrice($service, 1);

                // Use effective markup in DB so admin list reflects what users are paying.
                $markupType = $priceData['markup_type'] === 'percent' ? 'Percent' : 'Fixed';
                $markupValue = (float) $priceData['markup_value'];

                SmmServicePrice::updateOrCreate(
                    ['smm_service_id' => $service->id],
                    [
                        'markup_type' => $markupType,
                        'markup_value' => $markupValue,
                        'final_price' => $priceData['rate_per_unit'],
                        'last_synced_at' => now(),
                        'is_active' => true,
                    ]
                );

                $results['updated']++;
            } catch (\Exception $e) {
                Log::error('Failed to sync SMM service', [
                    'service_id' => $cpService['service'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }
}
