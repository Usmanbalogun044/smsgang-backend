<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmmOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminSmmOrderController extends Controller
{
    /**
     * Get all SMM orders for admin
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $status = $request->query('status');
            $search = $request->query('search');

            $query = SmmOrder::with(['service', 'user']);

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                })->orWhere('crestpanel_order_id', 'like', "%{$search}%");
            }

            $orders = $query->latest()->paginate($perPage);

            return response()->json([
                'data' => $orders->map(fn ($order) => [
                    'id' => $order->id,
                    'crestpanel_order_id' => $order->crestpanel_order_id,
                    'user' => $order->user ? [
                        'id' => $order->user->id,
                        'name' => $order->user->name,
                        'email' => $order->user->email,
                    ] : null,
                    'smm_service' => $order->service ? [
                        'id' => $order->service->id,
                        'name' => $order->service->name,
                        'category' => $order->service->category,
                        'type' => $order->service->type,
                    ] : null,
                    'quantity' => $order->quantity,
                    'runs' => $order->runs,
                    'interval' => $order->interval,
                    'comments' => $order->comments,
                    'price_per_unit' => (string) $order->price_per_unit,
                    'total_units' => $order->total_units,
                    'total_cost_ngn' => (string) $order->total_cost_ngn,
                    'charge_ngn' => (string) $order->charge_ngn,
                    'exchange_rate_used' => (string) $order->exchange_rate_used,
                    'markup_type_used' => $order->markup_type_used,
                    'markup_value_used' => (string) $order->markup_value_used,
                    'link' => $order->link,
                    'status' => $order->status->value,
                    'created_at' => $order->created_at->toIso8601String(),
                    'updated_at' => $order->updated_at->toIso8601String(),
                    'provider_payload' => $order->provider_payload,
                ]),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch SMM orders.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Get single SMM order details
     */
    public function show(SmmOrder $order): JsonResponse
    {
        try {
            return response()->json([
                'id' => $order->id,
                'crestpanel_order_id' => $order->crestpanel_order_id,
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ] : null,
                'service_name' => $order->service?->name,
                'quantity' => $order->quantity,
                'total_cost_ngn' => (string) $order->total_cost_ngn,
                'status' => $order->status->value,
                'link' => $order->link,
                'provider_payload' => $order->provider_payload,
                'created_at' => $order->created_at->toIso8601String(),
                'updated_at' => $order->updated_at->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch order.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }
}
