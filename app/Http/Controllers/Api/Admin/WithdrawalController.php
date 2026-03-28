<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(): JsonResponse
    {
        $withdrawals = Withdrawal::latest()->get();

        return response()->json([
            'data' => $withdrawals,
            'total' => (float) Withdrawal::sum('amount'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'note'   => 'nullable|string|max:255',
        ]);

        $withdrawal = Withdrawal::create($validated);

        return response()->json(['data' => $withdrawal], 201);
    }

    public function destroy(Withdrawal $withdrawal): JsonResponse
    {
        $withdrawal->delete();

        return response()->json(['message' => 'Withdrawal deleted.']);
    }
}
