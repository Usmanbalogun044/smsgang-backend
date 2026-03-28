<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePricingRequest;
use App\Http\Resources\ServicePriceResource;
use App\Models\ServicePrice;
use App\Services\PricingService;
use App\Jobs\SyncAllPricingJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminPricingController extends Controller
{
    public function __construct(
        private PricingService $pricingService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min((int) $request->integer('per_page', 100), 500));

        $query = ServicePrice::with(['service', 'country']);

        if ($request->has('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }

        if ($request->has('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->whereHas('service', fn($qq) => $qq
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('provider_service_code', 'like', "%{$search}%")
                )
                  ->orWhereHas('country', fn($qq) => $qq
                      ->where('name', 'like', "%{$search}%")
                      ->orWhere('provider_code', 'like', "%{$search}%")
                  );
            });
        }

        return ServicePriceResource::collection(
            $query
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage)
                ->appends($request->query())
        );
    }

    public function update(UpdatePricingRequest $request, ServicePrice $servicePrice): ServicePriceResource
    {
        $servicePrice->update([
            'markup_type' => $request->markup_type,
            'markup_value' => $request->markup_value,
            'is_active' => $request->boolean('is_active', $servicePrice->is_active),
            'final_price' => $this->pricingService->calculateFinalPrice(
                (float) $servicePrice->provider_price,
                \App\Enums\MarkupType::from($request->markup_type),
                (float) $request->markup_value,
            ),
        ]);

        return new ServicePriceResource($servicePrice->load(['service', 'country']));
    }

    public function sync(): JsonResponse
    {
        if (Cache::has('sync_in_progress')) {
            Log::warning('Manual Sync attempt blocked: Sync already in progress.');
            return response()->json(['message' => 'Sync is already running in the background.'], 400);
        }

        try {
            Log::info('Admin triggered manual SyncAllPricingJob from Dashboard.');
            
            // Set cache to prevent double clicks
            Cache::put('sync_in_progress', true, 1800); 

            // Dispatch the job
            SyncAllPricingJob::dispatch();

            return response()->json([
                'message' => 'Sync started in the background. Please monitor storage/logs/laravel.log for progress.',
                'status' => 'dispatched'
            ]);

        } catch (\Exception $e) {
            Cache::forget('sync_in_progress');
            Log::error('Failed to dispatch Sync job: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to start sync: ' . $e->getMessage()], 500);
        }
    }
}
