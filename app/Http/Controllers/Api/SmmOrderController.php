<?php

namespace App\Http\Controllers\Api;

use App\Enums\SmmOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\SmmOrder;
use App\Models\SmmService;
use App\Services\CrestPanelService;
use App\Services\SmmPricingService;
use App\Services\TelegramNotificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmmOrderController extends Controller
{
    public function __construct(
        private CrestPanelService $crestPanelService,
        private SmmPricingService $smmPricingService,
        private WalletService $walletService,
        private TelegramNotificationService $telegramService,
    ) {}

    /**
     * Create a new SMM order
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'smm_service_id' => ['required', 'exists:smm_services,id'],
                'link' => ['required', 'string', 'url'],
                'quantity' => ['required', 'integer', 'min:1'],
                'runs' => ['nullable', 'integer', 'min:1'],
                'interval' => ['nullable', 'integer', 'min:1'],
                'comments' => ['nullable', 'string'],
            ]);

            $user = $request->user();
            $service = SmmService::findOrFail($validated['smm_service_id']);

            // Validate quantity is within service limits
            if ($validated['quantity'] < $service->min || $validated['quantity'] > $service->max) {
                return response()->json([
                    'message' => "Quantity must be between {$service->min} and {$service->max}.",
                    'error' => 'invalid_quantity',
                ], 422);
            }

            // Calculate price
            $priceData = $this->smmPricingService->calculatePrice($service, $validated['quantity']);
            $finalPriceNgn = $priceData['total_price'];

            // Check wallet balance
            $wallet = $this->walletService->getOrCreateWallet($user);
            if ($wallet->balance < $finalPriceNgn) {
                return response()->json([
                    'message' => 'Insufficient wallet balance.',
                    'error' => 'insufficient_balance',
                    'required' => $finalPriceNgn,
                    'available' => $wallet->balance,
                    'deficit' => $finalPriceNgn - $wallet->balance,
                ], 422);
            }

            // Prepare order data
            $effectiveTotalUnits = (int) $validated['quantity'] * (int) max(1, (int) ($validated['runs'] ?? 1));
            $markupTypeUsed = strtolower((string) ($priceData['markup_type'] ?? 'fixed'));
            $markupValueUsed = (float) ($priceData['markup_value'] ?? 0);

            // STEP 1: Create order record LOCALLY FIRST (status: pending_provider_confirmation)
            // This ensures we have a record even if CrestPanel call fails
            $order = SmmOrder::create([
                'user_id' => $user->id,
                'smm_service_id' => $service->id,
                'crestpanel_order_id' => null,  // Will be updated after CrestPanel success
                'link' => $validated['link'],
                'quantity' => $validated['quantity'],
                'runs' => $validated['runs'] ?? null,
                'interval' => $validated['interval'] ?? null,
                'comments' => $validated['comments'] ?? null,
                'price_per_unit' => $priceData['rate_per_unit'],
                'total_units' => $effectiveTotalUnits,
                'total_cost_ngn' => $finalPriceNgn,
                'exchange_rate_used' => 1,
                'markup_type_used' => $markupTypeUsed,
                'markup_value_used' => $markupValueUsed,
                'provider_payload' => null,  // Will be updated after CrestPanel response
                'status' => SmmOrderStatus::PendingProviderConfirmation->value,
            ]);

            // STEP 2: Deduct from wallet (lock funds locally)
            $this->walletService->deductFunds(
                $user,
                $priceData['total_price'],
                "smm_order_{$order->id}",
                "SMM service purchase - {$service->name}"
            );

            // STEP 3: Call CrestPanel (now we have a local record + deduction)
            $cpOrder = $this->crestPanelService->createOrder([
                'service_id' => $service->crestpanel_service_id,
                'link' => $validated['link'],
                'quantity' => $validated['quantity'],
                'runs' => $validated['runs'] ?? null,
                'interval' => $validated['interval'] ?? null,
                'comments' => $validated['comments'] ?? null,
            ]);

            // STEP 4: Handle CrestPanel response
            if (!$cpOrder || isset($cpOrder['error']) || !isset($cpOrder['order'])) {
                // Log the actual provider error for support team debugging
                Log::channel('activity')->warning('CrestPanel createOrder returned error', [
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'error' => $cpOrder['error'] ?? 'Unknown error',
                    'data' => $validated,
                    'note' => 'Order created locally and funds deducted, but provider failed',
                ]);

                // Update order with failed status (but keep it in database with funds deducted)
                $order->update([
                    'status' => SmmOrderStatus::FailedAtProvider->value,
                    'provider_payload' => $cpOrder,
                ]);

                // Return user-friendly message with order proof
                return response()->json([
                    'message' => 'Order recorded but provider processing failed. Please contact support with order ID to resolve this.',
                    'error' => 'provider_error',
                    'order_id' => $order->id,
                    'reason' => 'Please screenshot this message for support',
                ], 422);
            }

            // STEP 5: CrestPanel succeeded - update order with provider details
            $order->update([
                'crestpanel_order_id' => (string) $cpOrder['order'],
                'provider_payload' => $cpOrder,
                'status' => SmmOrderStatus::Pending->value,
            ]);

            Log::channel('activity')->info('SMM order created successfully', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'crestpanel_order_id' => $order->crestpanel_order_id,
                'service' => $service->name,
                'quantity' => $validated['quantity'],
                'cost_ngn' => $priceData['total_price'],
                'status' => SmmOrderStatus::PendingProviderConfirmation->value . ' -> ' . SmmOrderStatus::Pending->value . ' (provider accepted)',
            ]);

            // Send Telegram notification about the new order
            $this->telegramService->sendSmmOrderNotification($order, $user, $service);

            return response()->json([
                'message' => 'Order created and sent to provider successfully.',
                'order' => [
                    'id' => $order->id,
                    'crestpanel_order_id' => $order->crestpanel_order_id,
                    'service_name' => $service->name,
                    'link' => $order->link,
                    'quantity' => $order->quantity,
                    'total_cost_ngn' => (string) $order->total_cost_ngn,
                    'status' => $order->status->value,
                    'created_at' => $order->created_at->toIso8601String(),
                ],
                'remaining_balance' => $this->walletService->getBalance($user),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('SMM order creation failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create order.',
                'error' => 'order_failed',
            ], 422);
        }
    }

    /**
     * Get user's SMM orders
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = (int) $request->query('per_page', 20);
            $status = $request->query('status');

            $query = SmmOrder::where('user_id', $user->id)
                ->with('service');

            if ($status) {
                $query->where('status', $status);
            }

            $orders = $query->latest()->paginate($perPage);

            return response()->json([
                'data' => $orders->map(fn ($order) => [
                    'id' => $order->id,
                    'crestpanel_order_id' => $order->crestpanel_order_id,
                    'service_name' => $order->service ? $order->service->name : 'Unknown',
                    'link' => $order->link,
                    'quantity' => $order->quantity,
                    'total_cost_ngn' => (string) $order->total_cost_ngn,
                    'status' => $order->status->value,
                    'created_at' => $order->created_at->toIso8601String(),
                ]),
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch orders.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }

    /**
     * Get single SMM order details with real-time status
     */
    public function show(SmmOrder $order, Request $request): JsonResponse
    {
        try {
            // Check authorization
            if ($order->user_id !== $request->user()->id) {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Get real-time status from CrestPanel
            $statusData = $this->crestPanelService->getOrderStatus($order->crestpanel_order_id);

            $status = $statusData['status'] ?? $order->status->value;
            $remains = $statusData['remains'] ?? null;
            $startCount = $statusData['start_count'] ?? null;
            $charge = isset($statusData['charge']) 
                ? (float) $statusData['charge'] 
                : $order->charge_ngn;

            return response()->json([
                'id' => $order->id,
                'crestpanel_order_id' => $order->crestpanel_order_id,
                'service' => $order->service ? [
                    'id' => $order->service->id,
                    'name' => $order->service->name,
                ] : null,
                'link' => $order->link,
                'quantity' => $order->quantity,
                'total_cost_ngn' => (string) $order->total_cost_ngn,
                'charge_ngn' => $charge ? (string) $charge : null,
                'status' => $status,
                'remains' => $remains,
                'start_count' => $startCount,
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
