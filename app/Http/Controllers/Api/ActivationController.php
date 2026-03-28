<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BuyActivationRequest;
use App\Http\Resources\ActivationResource;
use App\Http\Resources\OrderResource;
use App\Models\Activation;
use App\Models\Country;
use App\Models\Order;
use App\Models\Service;
use App\Models\Transaction;
use App\Services\ActivationService;
use App\Services\LendoverifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivationController extends Controller
{
    public function __construct(
        private ActivationService $activationService,
        private LendoverifyService $lendoverify,
    ) {}

    public function buy(BuyActivationRequest $request): JsonResponse
    {
        try {
            $service = Service::findOrFail($request->service_id);
            $country = Country::findOrFail($request->country_id);
            $user = $request->user();
            $price = (float) $service->price;

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

            // Create order immediately (no payment pending state)
            $order = $this->activationService->initiatePurchase(
                $user,
                $service,
                $country,
                (string) $request->input('operator'),
            );

            // Deduct from wallet
            $wallet->deductBalance($price);

            // Create transaction record
            Transaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'amount' => $price,
                'type' => 'debit',
                'operation_type' => 'wallet_debit',
                'status' => 'paid',
                'reference' => "order_{$order->id}",
                'description' => "SMS activation for {$service->name} ({$country->name})",
            ]);

            Log::channel('activity')->info('SMS activation purchased via wallet', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'service' => $service->name,
                'country' => $country->name,
                'price' => $price,
                'remaining_balance' => $wallet->fresh()->balance,
            ]);

            return response()->json([
                'message' => 'Order created. Activation in progress.',
                'order' => new OrderResource($order->load(['service', 'country', 'activation'])),
                'remaining_balance' => $wallet->fresh()->balance,
            ], 201);
        } catch (Throwable $e) {
            Log::error('Activation purchase error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to process your request. Please try again.',
                'error' => 'purchase_failed',
            ], 422);
        }
    }

    public function verifyPayment(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        if ($order->status->value !== 'pending') {
            return response()->json([
                'message' => 'Order is not awaiting payment.',
                'order' => new OrderResource($order->load(['service', 'country', 'activation'])),
            ], 422);
        }

        $result = $this->lendoverify->verifyReference($order->payment_reference);
        $data = $result['data'] ?? $result;

        $paymentStatusRaw = $data['paymentStatus'] ?? $data['status'] ?? null;
        $paymentStatus = is_string($paymentStatusRaw)
            ? strtolower(trim($paymentStatusRaw))
            : null;

        $successStatuses = ['paid', 'success', 'successful', 'completed'];
        $failedStatuses = ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'];

        if (in_array($paymentStatus, $failedStatuses, true)) {
            $this->logTransaction($order, $data, 'failed', $request);
            $order->update(['status' => 'failed']);

            return response()->json([
                'message' => 'Payment failed.',
                'code' => 'PAYMENT_FAILED',
                'payment_status' => $paymentStatusRaw,
            ], 422);
        }

        if (! in_array($paymentStatus, $successStatuses, true)) {
            $this->logTransaction($order, $data, 'pending', $request);
            return response()->json([
                'message' => 'Payment not confirmed yet.',
                'payment_status' => $paymentStatusRaw,
            ], 402);
        }

        $amountPaid = $data['amountPaid'] ?? $data['amount'] ?? 0;
        if (is_string($amountPaid) && str_contains($amountPaid, '.')) {
            $amountPaid = (float) $amountPaid;
        } elseif (is_numeric($amountPaid) && $amountPaid > 10000) {
            $amountPaid = $amountPaid / 100;
        }

        if (abs((float) $amountPaid - (float) $order->price) > 1) {
            return response()->json([
                'message' => 'Payment amount mismatch.',
            ], 422);
        }

        $order->update(['status' => 'paid']);

        try {
            $activation = $this->activationService->processAfterPayment($order);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'not enough user balance') || str_contains($message, 'insufficient')) {
                $this->logTransaction($order, $data, 'payment_received_issue', $request);
                Log::channel('activity')->error('Provider balance insufficient after successful payment', [
                    'order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'provider' => '5sim',
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Payment received, but number allocation is temporarily unavailable. Please contact support for immediate assistance.',
                    'code' => 'PROVIDER_INSUFFICIENT_BALANCE',
                    'order_id' => $order->id,
                ], 503);
            }

            $this->logTransaction($order, $data, 'payment_received_issue', $request);
            Log::channel('activity')->error('Payment verified but activation provisioning failed', [
                'order_id' => $order->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payment received, but activation provisioning failed. Please try again shortly or contact support.',
                'code' => 'ACTIVATION_PROVISIONING_FAILED',
                'order_id' => $order->id,
            ], 503);
        }

        Log::channel('activity')->info('Payment verified, number assigned', [
            'order_id' => $order->id,
            'activation_id' => $activation->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Payment verified. Number assigned.',
            'activation' => new ActivationResource($activation->load(['service', 'country'])),
        ]);
    }

    public function verifyPaymentByReference(Request $request, string $reference): JsonResponse
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return response()->json([
                'message' => 'Reference is required.',
                'errors'  => ['reference' => ['The reference field is required.']],
            ], 422);
        }

        // Hit gateway once to resolve the reference.
        $result = $this->lendoverify->verifyReference($reference);
        $data   = $result['data'] ?? $result;

        $paymentReference = $data['paymentReference'] ?? $data['reference'] ?? null;
        if (! is_string($paymentReference) || trim($paymentReference) === '') {
            return response()->json([
                'message' => 'Payment reference not found in gateway response.',
            ], 422);
        }

        // Wrap in a DB transaction with a row-level lock so concurrent verify calls
        // cannot both reach processAfterPayment simultaneously (prevents buy-multiple exploit).
        return DB::transaction(function () use ($request, $data, $paymentReference) {
            $order = Order::lockForUpdate()
                ->where('payment_reference', $paymentReference)
                ->where('user_id', $request->user()->id)
                ->first();

            if (! $order) {
                return response()->json([
                    'message' => 'Order not found for this payment reference.',
                ], 404);
            }

            // Idempotency: activation already exists — return it immediately.
            if ($order->activation) {
                $this->logTransaction($order, $data, 'paid', $request);
                return response()->json([
                    'message'    => 'Payment already verified. Number assigned.',
                    'activation' => new ActivationResource($order->activation->load(['service', 'country'])),
                ]);
            }

            // Check gateway payment status.
            $paymentStatusRaw = $data['paymentStatus'] ?? $data['status'] ?? null;
            $paymentStatus    = is_string($paymentStatusRaw) ? strtolower(trim($paymentStatusRaw)) : null;

            $successStatuses = ['paid', 'success', 'successful', 'completed'];
            $failedStatuses = ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'];

            if (in_array($paymentStatus, $failedStatuses, true)) {
                $this->logTransaction($order, $data, 'failed', $request);
                if ($order->status->value === 'pending') {
                    $order->update(['status' => 'failed']);
                }

                return response()->json([
                    'message'        => 'Payment failed.',
                    'code'           => 'PAYMENT_FAILED',
                    'payment_status' => $paymentStatusRaw,
                ], 422);
            }

            if (! in_array($paymentStatus, $successStatuses, true)) {
                $this->logTransaction($order, $data, 'pending', $request);
                return response()->json([
                    'message'        => 'Payment not confirmed yet.',
                    'payment_status' => $paymentStatusRaw,
                ], 402);
            }

            // Amount sanity check.
            $amountPaid = $data['amountPaid'] ?? $data['amount'] ?? 0;
            if (is_numeric($amountPaid) && (float) $amountPaid > 10000) {
                $amountPaid = (float) $amountPaid / 100;
            }
            if (abs((float) $amountPaid - (float) $order->price) > 1) {
                return response()->json(['message' => 'Payment amount mismatch.'], 422);
            }

            // Guard: order already moved past 'pending' — do NOT provision again.
            // This stops a reload-based exploit from spawning multiple activations on one payment.
            if ($order->status->value !== 'pending') {
                return response()->json([
                    'message'  => 'Payment received, but order is currently being processed. Please contact support if this persists.',
                    'code'     => 'ORDER_ALREADY_PROCESSED',
                    'order_id' => $order->id,
                    'status'   => $order->status->value,
                ], 422);
            }

            // Log the confirmed transaction.
            $this->logTransaction($order, $data, 'paid', $request);

            $order->update(['status' => 'paid']);

            try {
                $activation = $this->activationService->processAfterPayment($order);
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());

                if (str_contains($msg, 'not enough user balance') || str_contains($msg, 'insufficient')) {
                    $this->logTransaction($order, $data, 'payment_received_issue', $request);
                    Log::channel('activity')->error('Provider balance insufficient after payment verified', [
                        'order_id' => $order->id,
                        'user_id'  => $request->user()->id,
                        'provider' => '5sim',
                        'error'    => $e->getMessage(),
                    ]);
                    return response()->json([
                        'message'  => 'Payment received, but number allocation is temporarily unavailable. Please contact support.',
                        'code'     => 'PROVIDER_INSUFFICIENT_BALANCE',
                        'order_id' => $order->id,
                    ], 503);
                }

                $this->logTransaction($order, $data, 'payment_received_issue', $request);
                Log::channel('activity')->error('Payment verified but provisioning failed', [
                    'order_id' => $order->id,
                    'user_id'  => $request->user()->id,
                    'error'    => $e->getMessage(),
                ]);
                return response()->json([
                    'message'  => 'Payment received, but activation provisioning failed. Please contact support.',
                    'code'     => 'ACTIVATION_PROVISIONING_FAILED',
                    'order_id' => $order->id,
                ], 503);
            }

            Log::channel('activity')->info('Payment verified via reference, number assigned', [
                'order_id'      => $order->id,
                'activation_id' => $activation->id,
                'user_id'       => $request->user()->id,
            ]);

            return response()->json([
                'message'    => 'Payment verified. Number assigned.',
                'activation' => new ActivationResource($activation->load(['service', 'country'])),
            ]);
        });
    }

    /**
     * Log or update a payment transaction record for audit and admin tracking.
     * Never downgrades a paid transaction back to a pending state.
     */
    private function logTransaction(Order $order, array $gatewayData, string $status, Request $request): void
    {
        try {
            $existing = Transaction::where('reference', $order->payment_reference)->first();

            $attrs = [
                'user_id'           => $order->user_id,
                'order_id'          => $order->id,
                'gateway'           => 'lendoverify',
                'gateway_reference' => $gatewayData['paymentReference'] ?? $gatewayData['reference'] ?? null,
                'amount'            => (float) $order->price,
                'currency'          => 'NGN',
                'status'            => $status,
                'description'       => "SMS Activation – order #{$order->id}",
                'ip_address'        => $request->ip(),
                'user_agent'        => substr((string) $request->userAgent(), 0, 255),
                'gateway_response'  => $gatewayData,
                'verified_at'       => $status === 'paid' ? now() : ($existing?->verified_at),
            ];

            if ($existing) {
                // Never downgrade a settled/received transaction state back to pending/failed.
                if (in_array($existing->status, ['paid', 'payment_received_issue'], true) && in_array($status, ['pending', 'failed'], true)) {
                    return;
                }
                $existing->update($attrs);
            } else {
                Transaction::create(array_merge(['reference' => $order->payment_reference], $attrs));
            }
        } catch (Throwable $e) {
            Log::warning('Failed to log transaction', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
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

        return new ActivationResource($activation->load(['service', 'country']));
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

        $smsCode = $this->activationService->checkForSms($activation);

        return response()->json([
            'activation' => new ActivationResource($activation->fresh()->load(['service', 'country'])),
            'sms_code' => $smsCode,
        ]);
    }

    public function cancel(Request $request, Activation $activation): JsonResponse
    {
        $this->authorize('cancel', $activation);

        $this->activationService->cancelActivation($activation);

        Log::channel('activity')->info('Activation cancelled by user', [
            'activation_id' => $activation->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Activation cancelled.',
            'activation' => new ActivationResource($activation->fresh()->load(['service', 'country'])),
        ]);
    }
}
