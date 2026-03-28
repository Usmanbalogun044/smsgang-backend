<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\ActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LendoverifyWebhookController extends Controller
{
    public function __construct(
        private ActivationService $activationService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? $payload;

        Log::channel('activity')->info('Webhook received', [
            'event' => $event,
            'reference' => $data['paymentReference'] ?? 'unknown',
        ]);

        $paymentStatusRaw = $data['paymentStatus'] ?? $data['status'] ?? null;
        $paymentStatus = is_string($paymentStatusRaw) ? strtolower(trim($paymentStatusRaw)) : null;

        $successEvent = in_array($event, ['collection.successful', 'payment.successful'], true);
        $failedEvent = in_array($event, ['collection.failed', 'payment.failed'], true);
        $successStatus = in_array($paymentStatus, ['paid', 'success', 'successful', 'completed'], true);
        $failedStatus = in_array($paymentStatus, ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'], true);

        if (! $successEvent && ! $failedEvent && ! $successStatus && ! $failedStatus && empty($data['success'])) {
            return response()->json(['message' => 'Event ignored.']);
        }

        $reference = $data['paymentReference'] ?? $data['reference'] ?? null;

        if (! $reference) {
            return response()->json(['message' => 'No reference found.'], 400);
        }

        return DB::transaction(function () use ($reference, $request, $data) {
            $order = Order::lockForUpdate()->where('payment_reference', $reference)->first();

            if (! $order) {
                Log::channel('activity')->warning('Webhook: order not found', ['reference' => $reference]);

                return response()->json(['message' => 'Order not found.'], 404);
            }

            $paymentStatusRaw = $data['paymentStatus'] ?? $data['status'] ?? null;
            $paymentStatus = is_string($paymentStatusRaw) ? strtolower(trim($paymentStatusRaw)) : null;
            $failedStatus = in_array($paymentStatus, ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'], true);

            if ($failedStatus) {
                $this->logTransaction($order, $data, 'failed', $request);
                if ($order->status === OrderStatus::Pending) {
                    $order->update(['status' => OrderStatus::Failed]);
                }

                return response()->json(['message' => 'Payment failed recorded.']);
            }

            if ($order->activation) {
                $this->logTransaction($order, $data, 'paid', $request);
                return response()->json(['message' => 'Already processed.']);
            }

            if ($order->status !== OrderStatus::Pending) {
                $this->logTransaction($order, $data, 'paid', $request);
                return response()->json(['message' => 'Order already processed.']);
            }

            $this->logTransaction($order, $data, 'paid', $request);
            $order->update(['status' => OrderStatus::Paid]);

            try {
                $this->activationService->processAfterPayment($order);
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());
                $status = (str_contains($msg, 'not enough user balance') || str_contains($msg, 'insufficient'))
                    ? 'payment_received_issue'
                    : 'payment_received_issue';

                $this->logTransaction($order, $data, $status, $request);

                Log::channel('activity')->error('Webhook: activation failed after payment', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'transaction_status' => $status,
                ]);
            }

            return response()->json(['message' => 'OK']);
        });
    }

    /**
     * Log or update transaction from webhook events.
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
                'description'       => "SMS Activation - order #{$order->id}",
                'ip_address'        => $request->ip(),
                'user_agent'        => substr((string) $request->userAgent(), 0, 255),
                'gateway_response'  => $gatewayData,
                'verified_at'       => in_array($status, ['paid', 'payment_received_issue'], true)
                    ? now()
                    : ($existing?->verified_at),
            ];

            if ($existing) {
                if (in_array($existing->status, ['paid', 'payment_received_issue'], true) && in_array($status, ['pending', 'failed'], true)) {
                    return;
                }
                $existing->update($attrs);
            } else {
                Transaction::create(array_merge(['reference' => $order->payment_reference], $attrs));
            }
        } catch (Throwable $e) {
            Log::warning('Webhook transaction log failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
