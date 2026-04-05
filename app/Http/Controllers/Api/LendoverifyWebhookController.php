<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LendoverifyWebhookController extends Controller
{
    private ?bool $transactionOperationTypeColumnExists = null;

    public function __construct(
        private WalletService $walletService,
    ) {}

    private function hasTransactionOperationTypeColumn(): bool
    {
        if ($this->transactionOperationTypeColumnExists === null) {
            $this->transactionOperationTypeColumnExists = Schema::hasColumn('transactions', 'operation_type');
        }

        return $this->transactionOperationTypeColumnExists;
    }

    private function resolveReference(array $payload, array $data): ?string
    {
        $candidates = [
            $data['paymentReference'] ?? null,
            $data['payment_reference'] ?? null,
            $data['reference'] ?? null,
            $data['transactionReference'] ?? null,
            $data['transaction_reference'] ?? null,
            $data['trxref'] ?? null,
            $data['txnRef'] ?? null,
            $data['txn_ref'] ?? null,
            $data['metadata']['reference'] ?? null,
            $data['metadata']['paymentReference'] ?? null,
            $data['meta']['reference'] ?? null,
            $payload['paymentReference'] ?? null,
            $payload['payment_reference'] ?? null,
            $payload['reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolveEventType(array $payload): ?string
    {
        $event = $payload['event'] ?? $payload['eventType'] ?? null;

        return is_string($event) && trim($event) !== ''
            ? trim($event)
            : null;
    }

    private function resolvePaymentStatus(array $payload, array $data): ?string
    {
        $statusRaw =
            $data['paymentStatus']
            ?? $data['payment_status']
            ?? $data['status']
            ?? $data['payment']['status']
            ?? $payload['paymentStatus']
            ?? $payload['payment_status']
            ?? $payload['status']
            ?? null;

        if (! is_string($statusRaw)) {
            return null;
        }

        $status = strtolower(trim($statusRaw));

        return $status !== '' ? $status : null;
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event = $this->resolveEventType($payload);
        $data = $payload['data'] ?? $payload['eventData'] ?? $payload;
        if (! is_array($data)) {
            $data = [];
        }
        $reference = $this->resolveReference($payload, is_array($data) ? $data : []);

        Log::channel('activity')->info('Webhook received - Wallet Funding Only', [
            'event' => $event,
            'reference' => $reference ?? 'unknown',
            'payload_keys' => array_keys(is_array($data) ? $data : []),
        ]);

        $paymentStatus = $this->resolvePaymentStatus($payload, $data);

        $successEvent = in_array($event, ['collection.successful', 'payment.successful'], true);
        $failedEvent = in_array($event, ['collection.failed', 'payment.failed'], true);
        $successStatus = in_array($paymentStatus, ['paid', 'success', 'successful', 'completed'], true);
        $failedStatus = in_array($paymentStatus, ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'], true);

        if (! $successEvent && ! $failedEvent && ! $successStatus && ! $failedStatus && empty($data['success']) && empty($payload['success'])) {
            return response()->json(['message' => 'Event ignored.']);
        }

        if (! $reference) {
            Log::channel('activity')->warning('Webhook received without a wallet reference', [
                'event' => $event,
                'payload_keys' => array_keys(is_array($data) ? $data : []),
            ]);

            return response()->json(['message' => 'No reference found.'], 400);
        }

        // ONLY handle wallet funding (reference starts with WALLET_)
        if (strpos($reference, 'WALLET_') === 0) {
            return DB::transaction(function () use ($reference, $request, $data) {
                $transactionQuery = Transaction::lockForUpdate()
                    ->where('reference', $reference);

                if ($this->hasTransactionOperationTypeColumn()) {
                    $transactionQuery->where('operation_type', 'wallet_fund');
                }

                $transaction = $transactionQuery->first();

                if (!$transaction) {
                    Log::channel('activity')->warning('Webhook: wallet transaction not found', ['reference' => $reference]);
                    return response()->json(['message' => 'Wallet transaction not found.'], 404);
                }

                return $this->handleWalletFundingWebhook($transaction, $data, $request, $reference);
            });
        }

        // Orders no longer use webhooks - they use wallet deduction
        Log::channel('activity')->warning('Webhook reference not recognized - expected WALLET_ prefix', [
            'reference' => $reference,
            'event' => $event,
        ]);

        return response()->json(['message' => 'Invalid reference format. Expected WALLET_ prefix for wallet funding.'], 400);
    }

    /**
     * Handle wallet funding webhook
     */
    private function handleWalletFundingWebhook(Transaction $transaction, array $data, Request $request, string $reference): JsonResponse
    {
        $paymentStatus = $this->resolvePaymentStatus([], $data);
        $failedStatus = in_array($paymentStatus, ['failed', 'cancelled', 'canceled', 'declined', 'abandoned'], true);
        $successStatus = in_array($paymentStatus, ['paid', 'success', 'successful', 'completed'], true);

        try {
            if ($failedStatus) {
                // Mark transaction as failed
                $transaction->update([
                    'status' => 'failed',
                    'gateway_response' => $data,
                    'gateway_reference' => $reference,
                ]);

                Log::channel('activity')->info('Wallet funding failed', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'reference' => $transaction->reference,
                    'status' => 'failed',
                    'amount' => $transaction->amount,
                ]);

                return response()->json(['message' => 'Payment failed recorded.']);
            }

            if ($successStatus && $transaction->status !== 'paid') {
                // Get amount from webhook data
                $amount = (float) ($data['amountPaid'] ?? $data['amount'] ?? $transaction->amount);
                if ($amount > 10000) {
                    $amount = $amount / 100;
                }

                // Add funds to wallet
                $user = User::find($transaction->user_id);
                if ($user) {
                    $this->walletService->addFunds($user, $amount, $transaction->reference);

                    $transaction->update([
                        'gateway_response' => $data,
                        'gateway_reference' => $reference,
                        'amount' => $amount,
                    ]);

                    Log::channel('activity')->info('Wallet funding successful', [
                        'transaction_id' => $transaction->id,
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'final_balance' => $this->walletService->getBalance($user),
                    ]);
                } else {
                    Log::error('Wallet funding webhook: user not found', [
                        'transaction_id' => $transaction->id,
                        'user_id' => $transaction->user_id,
                    ]);
                }

                return response()->json(['message' => 'Wallet funded successfully.']);
            }

            // Already processed
            Log::channel('activity')->info('Webhook: wallet transaction already processed', [
                'transaction_id' => $transaction->id,
                'status' => $transaction->status,
            ]);

            return response()->json(['message' => 'Already processed.']);
        } catch (Throwable $e) {
            Log::error('Wallet funding webhook error', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $transaction->update(['status' => 'processing_error']);

            return response()->json(['message' => 'Error processing wallet funding.'], 500);
        }
    }
}
