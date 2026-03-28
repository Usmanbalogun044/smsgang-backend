<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmmService;
use App\Models\SmmServicePrice;
use App\Jobs\SyncSmmServicesJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

class AdminSmmServiceController extends Controller
{
    /**
     * Get all SMM services with pricing for admin
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 50);
            $search = $request->query('search');
            $category = $request->query('category');
            $active = $request->query('active');

            $query = SmmService::with(['prices' => function ($q) {
                $q->where('is_active', true);
            }]);

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            if ($category) {
                $query->where('category', $category);
            }

            if ($active !== null) {
                $query->where('is_active', (bool) $active);
            }

            $services = $query->paginate($perPage);

            return response()->json([
                'data' => $services->map(fn ($service) => [
                    'id' => $service->id,
                    'crestpanel_service_id' => $service->crestpanel_service_id,
                    'smm_service' => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'type' => $service->type,
                        'rate' => (string) $service->rate,
                        'min' => $service->min,
                        'max' => $service->max,
                        'refill' => (bool) $service->refill,
                        'cancel' => (bool) $service->cancel,
                    ],
                    'markup_type' => $service->prices->first()?->markup_type ?? 'Fixed',
                    'markup_value' => (string) ($service->prices->first()?->markup_value ?? 0),
                    'final_price' => (string) ($service->prices->first()?->final_price ?? 0),
                    'is_active' => $service->is_active,
                    'last_synced_at' => $service->last_synced_at?->toIso8601String(),
                    'created_at' => $service->created_at?->toIso8601String(),
                    'updated_at' => $service->updated_at?->toIso8601String(),
                    'provider_payload' => $service->provider_payload,
                ]),
                'meta' => [
                    'current_page' => $services->currentPage(),
                    'per_page' => $services->perPage(),
                    'total' => $services->total(),
                    'last_page' => $services->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch SMM services.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Toggle SMM service active status
     */
    public function toggle(SmmService $service, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);

            $service->update(['is_active' => $validated['is_active']]);

            return response()->json([
                'message' => 'Service status updated successfully.',
                'service' => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'is_active' => $service->is_active,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to update service status.',
                'error' => 'update_failed',
            ], 422);
        }
    }

    /**
     * Sync SMM services from CrestPanel
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            // Dispatch the sync job to the queue
            Bus::dispatch(new SyncSmmServicesJob());

            return response()->json([
                'message' => 'Sync job dispatched. Services will be updated in the background.',
                'status' => 'queued',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to dispatch sync job.',
                'error' => 'sync_failed',
            ], 422);
        }
    }
}
