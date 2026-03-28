<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivationStatus;
use App\Enums\OrderStatus;
use App\Enums\SmmOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivationResource;
use App\Http\Resources\AdminStatsResource;
use App\Models\Activation;
use App\Models\Order;
use App\Models\SmmOrder;
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
        $completedSmsOrders = Order::query()->where('status', OrderStatus::Completed);
        $completedSmmOrders = SmmOrder::query()->where('status', SmmOrderStatus::Completed->value);

        $smsTotalRevenue = (float) (clone $completedSmsOrders)->sum('price');
        $smsTotalProfit = (float) (clone $completedSmsOrders)->sum('profit_amount');
        $smsRevenueToday = (float) (clone $completedSmsOrders)
            ->whereDate('created_at', today())->sum('price');
        $smsRevenueWeek = (float) (clone $completedSmsOrders)
            ->where('created_at', '>=', now()->startOfWeek())->sum('price');
        $smsRevenueMonth = (float) (clone $completedSmsOrders)
            ->where('created_at', '>=', now()->startOfMonth())->sum('price');
        $smsProfitToday = (float) (clone $completedSmsOrders)
            ->whereDate('created_at', today())->sum('profit_amount');
        $smsProfitWeek = (float) (clone $completedSmsOrders)
            ->where('created_at', '>=', now()->startOfWeek())->sum('profit_amount');
        $smsProfitMonth = (float) (clone $completedSmsOrders)
            ->where('created_at', '>=', now()->startOfMonth())->sum('profit_amount');

        $smmTotalRevenue = (float) (clone $completedSmmOrders)->sum('total_cost_ngn');
        $smmTotalCost = (float) (clone $completedSmmOrders)->sum('charge_ngn');
        $smmTotalProfit = max(0, round($smmTotalRevenue - $smmTotalCost, 2));

        $smmRevenueToday = (float) (clone $completedSmmOrders)
            ->whereDate('created_at', today())->sum('total_cost_ngn');
        $smmRevenueWeek = (float) (clone $completedSmmOrders)
            ->where('created_at', '>=', now()->startOfWeek())->sum('total_cost_ngn');
        $smmRevenueMonth = (float) (clone $completedSmmOrders)
            ->where('created_at', '>=', now()->startOfMonth())->sum('total_cost_ngn');

        $smmCostToday = (float) (clone $completedSmmOrders)
            ->whereDate('created_at', today())->sum('charge_ngn');
        $smmCostWeek = (float) (clone $completedSmmOrders)
            ->where('created_at', '>=', now()->startOfWeek())->sum('charge_ngn');
        $smmCostMonth = (float) (clone $completedSmmOrders)
            ->where('created_at', '>=', now()->startOfMonth())->sum('charge_ngn');

        $smmProfitToday = max(0, round($smmRevenueToday - $smmCostToday, 2));
        $smmProfitWeek = max(0, round($smmRevenueWeek - $smmCostWeek, 2));
        $smmProfitMonth = max(0, round($smmRevenueMonth - $smmCostMonth, 2));

        $smsTotalOrders = Order::count();
        $smmTotalOrders = SmmOrder::count();
        $smsCompletedCount = (clone $completedSmsOrders)->count();
        $smmCompletedCount = (clone $completedSmmOrders)->count();

        return new AdminStatsResource([
            'total_orders' => $smsTotalOrders + $smmTotalOrders,
            'completed_sales_count' => $smsCompletedCount + $smmCompletedCount,
            'total_revenue' => round($smsTotalRevenue + $smmTotalRevenue, 2),
            'total_profit' => round($smsTotalProfit + $smmTotalProfit, 2),
            'sms_total_orders' => $smsTotalOrders,
            'smm_total_orders' => $smmTotalOrders,
            'sms_completed_sales_count' => $smsCompletedCount,
            'smm_completed_sales_count' => $smmCompletedCount,
            'sms_total_revenue' => $smsTotalRevenue,
            'smm_total_revenue' => $smmTotalRevenue,
            'sms_total_profit' => $smsTotalProfit,
            'smm_total_profit' => $smmTotalProfit,
            'active_activations' => Activation::whereNotIn('status', [
                ActivationStatus::Completed->value,
                ActivationStatus::Expired->value,
                ActivationStatus::Cancelled->value,
            ])->count(),
            'registered_users' => User::count(),
            'revenue_today' => round($smsRevenueToday + $smmRevenueToday, 2),
            'revenue_week' => round($smsRevenueWeek + $smmRevenueWeek, 2),
            'revenue_month' => round($smsRevenueMonth + $smmRevenueMonth, 2),
            'profit_today' => round($smsProfitToday + $smmProfitToday, 2),
            'profit_week' => round($smsProfitWeek + $smmProfitWeek, 2),
            'profit_month' => round($smsProfitMonth + $smmProfitMonth, 2),
            'sms_revenue_today' => $smsRevenueToday,
            'sms_revenue_week' => $smsRevenueWeek,
            'sms_revenue_month' => $smsRevenueMonth,
            'sms_profit_today' => $smsProfitToday,
            'sms_profit_week' => $smsProfitWeek,
            'sms_profit_month' => $smsProfitMonth,
            'smm_revenue_today' => $smmRevenueToday,
            'smm_revenue_week' => $smmRevenueWeek,
            'smm_revenue_month' => $smmRevenueMonth,
            'smm_profit_today' => $smmProfitToday,
            'smm_profit_week' => $smmProfitWeek,
            'smm_profit_month' => $smmProfitMonth,
            'total_withdrawals' => (float) Withdrawal::sum('amount'),
        ]);
    }
}
