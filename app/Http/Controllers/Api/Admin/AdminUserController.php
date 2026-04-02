<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Activation;
use App\Models\Order;
use App\Models\SmmOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = User::query();

        if (request()->has('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (request()->has('role')) {
            $query->where('role', request('role'));
        }

        return UserResource::collection($query->latest()->paginate(50));
    }

    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'new_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'admins' => User::where('role', 'admin')->count(),
            'suspended' => User::where('status', 'suspended')->count(),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $ordersCount = Order::where('user_id', $user->id)->count();
        $activationsCount = Activation::whereHas('order', fn ($q) => $q->where('user_id', $user->id))->count();
        $smmOrdersCount = SmmOrder::where('user_id', $user->id)->count();
        $transactionsQuery = Transaction::where('user_id', $user->id);

        $stats = [
            'orders_count' => $ordersCount,
            'activations_count' => $activationsCount,
            'smm_orders_count' => $smmOrdersCount,
            'transactions_count' => (clone $transactionsQuery)->count(),
            'total_credited' => (float) (clone $transactionsQuery)->where('type', 'credit')->sum('amount'),
            'total_debited' => (float) (clone $transactionsQuery)->where('type', 'debit')->sum('amount'),
        ];

        $loginActivities = UserLoginActivity::where('user_id', $user->id)
            ->latest()
            ->limit(40)
            ->get(['id', 'event_type', 'ip_address', 'user_agent', 'context', 'created_at']);

        $recentOrders = Order::with(['service:id,name,slug', 'country:id,name,code'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(12)
            ->get();

        $recentSmmOrders = SmmOrder::with('service:id,name,category')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(12)
            ->get();

        $recentTransactions = Transaction::where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get(['id', 'type', 'operation_type', 'amount', 'status', 'reference', 'ip_address', 'created_at']);

        return response()->json([
            'user' => (new UserResource($user))->toArray(request()),
            'stats' => $stats,
            'login_activities' => $loginActivities,
            'recent_orders' => $recentOrders,
            'recent_smm_orders' => $recentSmmOrders,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        if (($request->validated()['status'] ?? null) === UserStatus::Suspended->value) {
            $user->tokens()->delete();
            $user->forceFill([
                'is_online' => false,
                'last_logout_at' => now(),
            ])->save();
        }

        Log::channel('activity')->info('Admin updated user', [
            'user_id' => $user->id,
            'changes' => $request->validated(),
        ]);

        return new UserResource($user);
    }
}
