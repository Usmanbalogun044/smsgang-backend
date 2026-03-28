<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use App\Services\LendoverifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $walletService,
        private LendoverifyService $lendoverify,
    ) {}

    /**
     * Get user's wallet balance
     */
    public function getBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $balance = $this->walletService->getBalance($user);

        return response()->json([
            'balance' => $balance,
            'currency' => 'NGN',
        ]);
    }

    /**
     * Fund wallet via Lendoverify
     */
    public function fund(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:500'],
            ]);

            $amount = (float) $validated['amount'];
            $user = $request->user();

            // Generate unique reference
            $reference = 'WALLET_' . $user->id . '_' . uniqid();

            // Keep redirect flow aligned with the existing frontend payment verification page.
            $redirectUrl = rtrim((string) config('app.verify_payment_url', config('app.frontend_url', config('app.url')) . '/verify-payment'), '/');

            // Lendoverify expects this payload shape via initializeTransaction.
            $result = $this->lendoverify->initializeTransaction([
                'amount' => (int) round($amount * 100),
                'customerEmail' => $user->email,
                'customerName' => $user->name,
                'paymentReference' => $reference,
                'paymentDescription' => 'Wallet Funding - SMS Gang',
                'redirectUrl' => $redirectUrl,
            ]);

            $data = $result['data'] ?? $result;
            $checkoutUrl = $data['checkout_url']
                ?? $data['authorization_url']
                ?? $data['authorizationUrl']
                ?? null;

            return response()->json([
                'message' => 'Wallet funding initiated. Please complete payment.',
                'checkout_url' => $checkoutUrl,
                'amount' => $amount,
                'currency' => 'NGN',
                'reference' => $reference,
                'fund_id' => $reference,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to initiate wallet funding.',
                'error' => 'fund_failed',
                'details' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verify wallet funding payment
     */
    public function verifyFunding(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reference' => ['required', 'string'],
            ]);

            $reference = $validated['reference'];
            $user = $request->user();

            // Verify payment with Lendoverify
            $result = $this->lendoverify->verifyReference($reference);
            $data = $result['data'] ?? $result;

            $paymentStatus = strtolower(trim($data['paymentStatus'] ?? $data['status'] ?? ''));
            
            if (!in_array($paymentStatus, ['paid', 'success', 'successful', 'completed'])) {
                return response()->json([
                    'message' => 'Payment not confirmed yet.',
                    'payment_status' => $paymentStatus,
                ], 402);
            }

            // Get amount from data
            $amount = (float) ($data['amountPaid'] ?? $data['amount'] ?? 0);
            if ($amount > 10000) {
                $amount = $amount / 100;
            }

            // Add funds to wallet
            $this->walletService->addFunds($user, $amount, $reference);

            $newBalance = $this->walletService->getBalance($user);

            return response()->json([
                'message' => 'Wallet funded successfully.',
                'amount_credited' => $amount,
                'new_balance' => $newBalance,
                'currency' => 'NGN',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Wallet funding verification failed', [
                'reference' => $request->input('reference'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to verify payment.',
                'error' => 'verification_failed',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }
    }

    /**
     * Get wallet transaction history
     */
    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $type = $request->query('type'); // debit or credit
            $period = $request->query('period'); // today, week, month, all
            $perPage = (int) $request->query('per_page', 20);

            $transactions = $this->walletService->getTransactions($user, $type, $period, $perPage);

            return response()->json([
                'data' => $transactions->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'operation' => $t->operation_type ?? ($t->type === 'credit' ? 'wallet_fund' : 'wallet_debit'),
                    'amount' => (string) $t->amount,
                    'reference' => $t->reference,
                    'description' => $t->description,
                    'status' => $t->status,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
                'pagination' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch transactions.',
                'error' => 'fetch_failed',
            ], 422);
        }
    }
}
