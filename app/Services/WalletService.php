<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalletService
{
    private ?bool $operationTypeColumnExists = null;

    private function hasOperationTypeColumn(): bool
    {
        if ($this->operationTypeColumnExists === null) {
            $this->operationTypeColumnExists = Schema::hasColumn('transactions', 'operation_type');
        }

        return $this->operationTypeColumnExists;
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

    private function getLockedWallet(User $user): Wallet
    {
        $this->getOrCreateWallet($user);

        return Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
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

            $wallet = $this->getLockedWallet($user);

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

            try {
                $transaction = Transaction::create($payload);
            } catch (QueryException $e) {
                $duplicateRef = ($e->errorInfo[1] ?? null) === 1062;
                if ($duplicateRef) {
                    return Transaction::where('reference', $reference)->firstOrFail();
                }
                throw $e;
            }

            $wallet->increment('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Deduct funds from wallet (for purchases)
     */
    public function deductFunds(User $user, float $amount, string $reference, string $description): ?Transaction
    {
        return DB::transaction(function () use ($user, $amount, $reference, $description) {
            $existing = Transaction::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = $this->getLockedWallet($user);

            if ((float) $wallet->balance < $amount) {
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

            try {
                $transaction = Transaction::create($payload);
            } catch (QueryException $e) {
                $duplicateRef = ($e->errorInfo[1] ?? null) === 1062;
                if ($duplicateRef) {
                    return Transaction::where('reference', $reference)->firstOrFail();
                }
                throw $e;
            }

            $wallet->decrement('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Refund funds to user's wallet
     */
    public function refundFunds(User $user, float $amount, string $reference, string $description): Transaction
    {
        return DB::transaction(function () use ($user, $amount, $reference, $description) {
            $existing = Transaction::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = $this->getLockedWallet($user);

            $payload = [
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'credit',
                'status' => 'paid',
                'reference' => $reference,
                'description' => $description,
            ];

            if ($this->hasOperationTypeColumn()) {
                $payload['operation_type'] = 'wallet_refund';
            }

            try {
                $transaction = Transaction::create($payload);
            } catch (QueryException $e) {
                $duplicateRef = ($e->errorInfo[1] ?? null) === 1062;
                if ($duplicateRef) {
                    return Transaction::where('reference', $reference)->firstOrFail();
                }
                throw $e;
            }

            $wallet->increment('balance', $amount);

            return $transaction;
        });
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
                  ->orWhere('operation_type', 'wallet_debit')
                  ->orWhere('operation_type', 'wallet_refund');
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
