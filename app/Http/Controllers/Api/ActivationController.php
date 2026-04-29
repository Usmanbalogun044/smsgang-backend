<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BuyActivationRequest;
use App\Http\Resources\ActivationResource;
use App\Http\Resources\OrderResource;
use App\Enums\OrderStatus;
use App\Models\Activation;
use App\Models\Country;
use App\Models\Service;
use App\Services\ActivationService;
use App\Services\TelegramNotificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivationController extends Controller
{

    public function buy(BuyActivationRequest $request): JsonResponse
    {
        $user = $request->user();
        $lockKey = sprintf(
            'lock:sms-buy:%d:%d:%d:%s',
            $user->id,
            (int) $request->service_id,
            (int) $request->country_id,
            (string) $request->input('operator', 'any')
        );
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Your previous order is still being processed. Please wait a few seconds and try again.',
                'error' => 'request_in_progress',
            ], 429);
        }

        try {
            $service = Service::findOrFail($request->service_id);
            $country = Country::findOrFail($request->country_id);

            // Create order first so we always use the exact calculated operator price.
            $order = app(ActivationService::class)->initiatePurchase(
                $user,
                $service,
                $country,
                (string) $request->input('operator'),
            );
            $price = (float) $order->price;

            if ($price <= 0) {
                $order->update(['status' => OrderStatus::Failed]);

                return response()->json([
                    'message' => 'Selected operator price is invalid. Please choose another operator.',
                    'error' => 'invalid_operator_price',
                ], 422);
            }

            // Get or create user's wallet
            $wallet = $user->wallet()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0]
            );

            // Check wallet balance
            if ($wallet->balance < $price) {
                return response()->json([
                    'message' => 'Insufficient wallet balance.',
                    'error' => 'insufficient_balance',
                    'required' => $price,
                    'available' => $wallet->balance,
                    'deficit' => $price - $wallet->balance,
                ], 422);
            }

            $debitTx = app(WalletService::class)->deductFunds(
                $user,
                $price,
                "SMS_ORDER_{$order->id}",
                "SMS activation for {$service->name} ({$country->name})"
            );

            if (!$debitTx) {
                // Balance could be consumed by a concurrent request after initial check.
                $order->update(['status' => OrderStatus::Failed]);

                return response()->json([
                    'message' => 'Insufficient wallet balance.',
                    'error' => 'insufficient_balance',
                ], 422);
            }

            // Link ledger transaction back to this order for admin tracing.
            if (! $debitTx->order_id) {
                $debitTx->update(['order_id' => $order->id]);
            }

            try {
                app(ActivationService::class)->processAfterPayment($order);
            } catch (Throwable $provisionError) {
                // Refund user instantly if provisioning fails after wallet debit.
                app(WalletService::class)->refundFunds(
                    $user,
                    $price,
                    "SMS_REFUND_ORDER_{$order->id}",
                    "Refund for failed SMS activation order #{$order->id}"
                );

                Log::channel('activity')->error('SMS activation provisioning failed after wallet debit, refunded user', [
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'price' => $price,
                    'error' => $provisionError->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Activation failed and your wallet was refunded automatically.',
                    'error' => 'activation_failed_refunded',
                ], 503);
            }

            $remainingBalance = app(WalletService::class)->getBalance($user);

            Log::channel('activity')->info('SMS activation purchased via wallet', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'service' => $service->name,
                'country' => $country->name,
                'price' => $price,
                'remaining_balance' => $remainingBalance,
            ]);

            app(TelegramNotificationService::class)->sendTransactionNotification(
                $user,
                $price,
                'debit',
                "SMS order #{$order->id} - {$service->name} ({$country->name})"
            );

            return response()->json([
                'message' => 'Order created. Activation in progress.',
                'order' => new OrderResource($order->load(['service', 'country', 'activation'])),
                'remaining_balance' => $remainingBalance,
            ], 201);
        } catch (Throwable $e) {
            Log::error('Activation purchase error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            
            return response()->json([
                'message' => 'Failed to process your request. Please try again.',
                'error' => 'purchase_failed',
            ], 422);
        } finally {
            optional($lock)->release();
        }
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $activations = Activation::with(['service', 'country', 'order'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(20);

        return ActivationResource::collection($activations);
    }

    public function show(Activation $activation): ActivationResource
    {
        $this->authorize('view', $activation);

        return new ActivationResource($activation->load(['service', 'country', 'order']));
    }

    public function checkSms(Activation $activation): JsonResponse
    {
        $this->authorize('view', $activation);

        if ($activation->isTerminal()) {
            return response()->json([
                'activation' => new ActivationResource($activation),
                'message' => 'Activation is already in terminal state.',
            ]);
        }

        $smsCode = app(ActivationService::class)->checkForSms($activation);

        return response()->json([
            'activation' => new ActivationResource($activation->fresh()->load(['service', 'country', 'order'])),
            'sms_code' => $smsCode,
        ]);
    }

    public function cancel(Request $request, Activation $activation): JsonResponse
    {
        $this->authorize('cancel', $activation);

        app(ActivationService::class)->cancelActivation($activation);

        Log::channel('activity')->info('Activation cancelled by user', [
            'activation_id' => $activation->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Activation cancelled.',
            'activation' => new ActivationResource($activation->fresh()->load(['service', 'country', 'order'])),
        ]);
    }
}
