<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmmService;
use App\Services\CrestPanelService;
use App\Services\SmmPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmmServiceController extends Controller
{

    private function buildServiceIntelligence(SmmService $service): array
    {
        $name = strtolower((string) $service->name);
        $category = strtolower((string) $service->category);
        $refill = (bool) $service->refill;
        $cancel = (bool) $service->cancel;

        $isPremium = str_contains($name, 'premium')
            || str_contains($name, 'high retention')
            || str_contains($name, 'hq')
            || str_contains($name, 'real');

        $isCheap = str_contains($name, 'cheap')
            || str_contains($name, 'budget')
            || str_contains($name, 'economy')
            || str_contains($name, 'low cost');

        if ($isPremium) {
            $tier = 'premium';
            $tierLabel = 'Premium (High Retention)';
            $dropRisk = '0-10';
            $startTime = '0-30 mins';
            $speedPerDay = '2K-8K/day';
            $recommendationTag = 'Best Retention';
        } elseif ($isCheap) {
            $tier = 'cheap';
            $tierLabel = 'Cheap (May Drop)';
            $dropRisk = '30-60';
            $startTime = '0-5 mins';
            $speedPerDay = '10K-80K/day';
            $recommendationTag = 'Trending';
        } else {
            $tier = 'standard';
            $tierLabel = 'Standard (Refill)';
            $dropRisk = '10-25';
            $startTime = '5-60 mins';
            $speedPerDay = '5K-30K/day';
            $recommendationTag = 'Recommended';
        }

        $marketLabel = $service->name;
        if (str_contains($category, 'instagram likes')) {
            $marketLabel = $tier === 'cheap'
                ? 'Instagram Likes - Fast Boost (Budget)'
                : ($tier === 'premium'
                    ? 'Instagram Likes - Premium Real Mix'
                    : 'Instagram Likes - Stable Growth (Refill)');
        }

        return [
            'quality_tier' => $tier,
            'quality_tier_label' => $tierLabel,
            'drop_risk_percent_range' => $dropRisk,
            'estimated_start_time' => $startTime,
            'estimated_speed_per_day' => $speedPerDay,
            'auto_refill_protected' => $refill,
            'supports_cancel' => $cancel,
            'recommendation_tag' => $recommendationTag,
            'market_label' => $marketLabel,
        ];
    }

    /**
     * Get all active SMM services
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();
            $category = $request->query('category');
            $type = $request->query('type');
            $search = $request->query('search');
            $perPage = (int) $request->query('per_page', 50);

            $query = SmmService::where('is_active', true);

            if ($category) {
                $query->where('category', $category);
            }

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            $services = $query->paginate($perPage);

            $categories = SmmService::query()
                ->where('is_active', true)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values();

            return response()->json([
                'data' => $services->getCollection()->map(function ($service) use ($authUser) {
                    $priceData = $this->smmPricingService->calculatePrice($service, 1, $authUser);
                    $intelligence = $this->buildServiceIntelligence($service);

                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'type' => $service->type,
                        'rate_per_1000' => (float) $service->rate,
                        'rate_per_unit' => (float) $priceData['rate_per_unit'],
                        'final_price' => (float) $priceData['rate_per_unit'],
                        'final_price_per_1000' => (float) $priceData['final_price_per_1000'],
                        'markup_type' => $priceData['markup_type'],
                        'markup_value' => (float) $priceData['markup_value'],
                        'min' => $service->min,
                        'max' => $service->max,
                        'refill' => $service->refill,
                        'cancel' => $service->cancel,
                        ...$intelligence,
                    ];
                }),
                'categories' => $categories,
                'meta' => [
                    'total' => $services->total(),
                    'per_page' => $services->perPage(),
                    'current_page' => $services->currentPage(),
                    'last_page' => $services->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch SMM services.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Get single SMM service details
     */
    public function show(SmmService $service): JsonResponse
    {
        try {
            if (!$service->is_active) {
                return response()->json([
                    'message' => 'Service not found.',
                ], 404);
            }

            $priceData = $this->smmPricingService->calculatePrice($service, 1, request()->user());
            $intelligence = $this->buildServiceIntelligence($service);

            return response()->json([
                'id' => $service->id,
                'crestpanel_id' => $service->crestpanel_service_id,
                'name' => $service->name,
                'category' => $service->category,
                'type' => $service->type,
                'rate_per_1000' => (float) $service->rate,
                'rate_per_unit' => (float) $priceData['rate_per_unit'],
                'final_price' => (float) $priceData['rate_per_unit'],
                'final_price_per_1000' => (float) $priceData['final_price_per_1000'],
                'markup_type' => $priceData['markup_type'],
                'markup_value' => (float) $priceData['markup_value'],
                'min' => $service->min,
                'max' => $service->max,
                'refill' => $service->refill,
                'cancel' => $service->cancel,
                ...$intelligence,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch service.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Get CrestPanel account balance (admin only)
     */
    public function getBalance(): JsonResponse
    {
        try {
            $crestPanelService = new CrestPanelService();
            $balance = $crestPanelService->getBalance();

            return response()->json([
                'balance' => $balance !== null ? number_format($balance, 2) : 'N/A',
                'last_updated' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch CrestPanel balance.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }
}
