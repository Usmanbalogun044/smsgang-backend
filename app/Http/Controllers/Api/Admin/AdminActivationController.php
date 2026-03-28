<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivationStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivationResource;
use App\Http\Resources\AdminStatsResource;
use App\Models\Activation;
use App\Models\Order;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\ActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class AdminActivationController extends Controller
{
    public function __construct(
        private ActivationService $activationService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Activation::with(['service', 'country', 'order.user']);

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('user_id')) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $request->integer('user_id')));
        }

        return ActivationResource::collection($query->latest()->paginate(50));
    }

    public function expire(Activation $activation): JsonResponse
    {
        if ($activation->isTerminal()) {
            return response()->json(['message' => 'Activation is already in terminal state.'], 422);
        }

        $this->activationService->expireActivation($activation);

        Log::channel('activity')->info('Admin force-expired activation', [
            'activation_id' => $activation->id,
        ]);

        return response()->json([
            'message' => 'Activation expired.',
            'activation' => new ActivationResource($activation->fresh()),
        ]);
    }

    public function stats(): AdminStatsResource
    {
        $completedOrders = Order::query()->where('status', OrderStatus::Completed);

        return new AdminStatsResource([
            'total_orders' => Order::count(),
            'completed_sales_count' => (clone $completedOrders)->count(),
            'total_revenue' => (clone $completedOrders)->sum('price'),
            'total_profit' => (clone $completedOrders)->sum('profit_amount'),
            'active_activations' => Activation::whereNotIn('status', [
                ActivationStatus::Completed->value,
                ActivationStatus::Expired->value,
                ActivationStatus::Cancelled->value,
            ])->count(),
            'registered_users' => User::count(),
            'revenue_today' => (clone $completedOrders)
                ->whereDate('created_at', today())->sum('price'),
            'revenue_week' => (clone $completedOrders)
                ->where('created_at', '>=', now()->startOfWeek())->sum('price'),
            'revenue_month' => (clone $completedOrders)
                ->where('created_at', '>=', now()->startOfMonth())->sum('price'),
            'profit_today' => (clone $completedOrders)
                ->whereDate('created_at', today())->sum('profit_amount'),
            'profit_week' => (clone $completedOrders)
                ->where('created_at', '>=', now()->startOfWeek())->sum('profit_amount'),
            'profit_month' => (clone $completedOrders)
                ->where('created_at', '>=', now()->startOfMonth())->sum('profit_amount'),
            'total_withdrawals' => (float) Withdrawal::sum('amount'),
        ]);
    }
}
