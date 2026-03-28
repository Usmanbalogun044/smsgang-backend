<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_orders' => $this['total_orders'],
            'completed_sales_count' => $this['completed_sales_count'],
            'total_revenue' => $this['total_revenue'],
            'total_profit' => $this['total_profit'],
            'active_activations' => $this['active_activations'],
            'registered_users' => $this['registered_users'],
            'revenue_today' => $this['revenue_today'],
            'revenue_week' => $this['revenue_week'],
            'revenue_month' => $this['revenue_month'],
            'profit_today' => $this['profit_today'],
            'profit_week' => $this['profit_week'],
            'profit_month' => $this['profit_month'],
            'total_withdrawals' => $this['total_withdrawals'],
        ];
    }
}
