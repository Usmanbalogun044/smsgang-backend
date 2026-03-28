<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\SmmServicePrice;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AdminSmmSettingsController
{
    /**
     * Get SMM settings
     */
    public function index(): JsonResponse
    {
        try {
            $globalMarkupFixed = (float) Setting::get('smm_global_markup_fixed', 500);
            $globalMarkupType = Setting::get('smm_global_markup_type', 'fixed');

            return response()->json([
                'global_markup_fixed' => $globalMarkupFixed,
                'global_markup_type' => $globalMarkupType,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch SMM settings.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Update SMM settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'global_markup_fixed' => ['required', 'numeric', 'min:0'],
                'global_markup_type' => ['required', 'in:fixed,percent'],
            ]);

            // Store in database using Setting model
            Setting::set('smm_global_markup_fixed', $validated['global_markup_fixed']);
            Setting::set('smm_global_markup_type', $validated['global_markup_type']);

            // Apply global markup to all existing SMM service prices so "for everything" works immediately.
            $globalType = strtolower((string) $validated['global_markup_type']) === 'percent' ? 'Percent' : 'Fixed';
            $globalValue = (float) $validated['global_markup_fixed'];

            SmmServicePrice::with('service:id,rate')
                ->chunkById(200, function ($prices) use ($globalType, $globalValue) {
                    foreach ($prices as $price) {
                        $ratePer1000 = (float) ($price->service?->rate ?? 0);
                        $basePerUnit = $ratePer1000 / 1000;

                        $finalPerUnit = $globalType === 'Percent'
                            ? ($basePerUnit * (1 + ($globalValue / 100)))
                            : ($basePerUnit + ($globalValue / 1000));

                        $price->update([
                            'markup_type' => $globalType,
                            'markup_value' => $globalValue,
                            'final_price' => round($finalPerUnit, 2),
                            'last_synced_at' => now(),
                        ]);
                    }
                });

            // Clear any cached pricing calculations
            Cache::forget('smm_pricing_settings');

            return response()->json([
                'message' => 'SMM settings updated successfully.',
                'global_markup_fixed' => $validated['global_markup_fixed'],
                'global_markup_type' => $validated['global_markup_type'],
                'applied_to_all_services' => true,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to update SMM settings.',
                'error' => 'update_failed',
            ], 422);
        }
    }

    /**
     * Update individual service markup
     */
    public function updateServiceMarkup(Request $request, int $serviceId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'markup_type' => ['required', 'in:Fixed,Percent'],
                'markup_value' => ['required', 'numeric', 'min:0'],
            ]);

            $servicePrice = SmmServicePrice::where('smm_service_id', $serviceId)->firstOrFail();

            $servicePrice->update([
                'markup_type' => $validated['markup_type'],
                'markup_value' => $validated['markup_value'],
            ]);

            return response()->json([
                'message' => 'Service markup updated successfully.',
                'service' => [
                    'id' => $servicePrice->id,
                    'smm_service_id' => $servicePrice->smm_service_id,
                    'markup_type' => $servicePrice->markup_type,
                    'markup_value' => $servicePrice->markup_value,
                    'final_price' => $servicePrice->final_price,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to update service markup.',
                'error' => 'update_failed',
            ], 422);
        }
    }
}
