<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    /**
     * User's own transactions — paginated, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = Transaction::with(['order'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return TransactionResource::collection($transactions);
    }
}
