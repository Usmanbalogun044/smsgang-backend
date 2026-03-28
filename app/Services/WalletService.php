<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalletService
{
    private function hasOperationTypeColumn(): bool
    {
        return Schema::hasColumn('transactions', 'operation_type');
    }

    /**
     * Get or create user's wallet
     */
    public function getOrCreateWallet(User $user): Wallet
    {
        return $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );
    }

    /**
     * Get user's wallet balance
     */
    public function getBalance(User $user): string
    {
        $wallet = $this->getOrCreateWallet($user);
        return (string) $wallet->balance;
    }

    /**
     * Add funds to user's wallet
     */
    public function addFunds(User $user, float $amount, string $reference): Transaction
    {
        return DB::transaction(function () use ($user, $amount, $reference) {
            // Idempotent by reference: repeated verify callbacks should not create duplicates
            // or re-credit wallet balance.
            $existing = Transaction::where('reference', $reference)->first();

            if ($existing) {
                return $existing;
            }

            $wallet = $this->getOrCreateWallet($user);

            $payload = [
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'credit',
                'status' => 'paid',
                'reference' => $reference,
                'description' => 'Wallet funding',
                'gateway' => 'lendoverify',
            ];

            if ($this->hasOperationTypeColumn()) {
                $payload['operation_type'] = 'wallet_fund';
            }

            $transaction = Transaction::create($payload);

            $wallet->addBalance($amount);

            return $transaction;
        });
    }

    /**
     * Deduct funds from wallet (for purchases)
     */
    public function deductFunds(User $user, float $amount, string $reference, string $description): ?Transaction
    {
        $wallet = $this->getOrCreateWallet($user);

        if ($wallet->balance < $amount) {
            return null; // Insufficient balance
        }

        $payload = [
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => 'debit',
            'status' => 'paid',
            'reference' => $reference,
            'description' => $description,
        ];

        if ($this->hasOperationTypeColumn()) {
            $payload['operation_type'] = 'wallet_debit';
        }

        $transaction = Transaction::create($payload);

        $wallet->deductBalance($amount);

        return $transaction;
    }

    /**
     * Get wallet transaction history
     */
    public function getTransactions(User $user, ?string $type = null, ?string $period = null, int $perPage = 20)
    {
        $query = Transaction::where('user_id', $user->id);

        if ($this->hasOperationTypeColumn()) {
            $query->where(function ($q) {
                $q->where('operation_type', 'wallet_fund')
                  ->orWhere('operation_type', 'wallet_debit');
            });
        } else {
            // Legacy schema fallback: wallet ledger is represented as credit/debit transaction types.
            $query->whereIn('type', ['credit', 'debit']);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($period) {
            $query = $this->filterByPeriod($query, $period);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Filter transactions by period
     */
    private function filterByPeriod($query, string $period)
    {
        return match ($period) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
            default => $query,
        };
    }
}
