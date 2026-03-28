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
            'sms_total_orders' => $this['sms_total_orders'],
            'smm_total_orders' => $this['smm_total_orders'],
            'sms_completed_sales_count' => $this['sms_completed_sales_count'],
            'smm_completed_sales_count' => $this['smm_completed_sales_count'],
            'sms_total_revenue' => $this['sms_total_revenue'],
            'smm_total_revenue' => $this['smm_total_revenue'],
            'sms_total_profit' => $this['sms_total_profit'],
            'smm_total_profit' => $this['smm_total_profit'],
            'active_activations' => $this['active_activations'],
            'registered_users' => $this['registered_users'],
            'revenue_today' => $this['revenue_today'],
            'revenue_week' => $this['revenue_week'],
            'revenue_month' => $this['revenue_month'],
            'profit_today' => $this['profit_today'],
            'profit_week' => $this['profit_week'],
            'profit_month' => $this['profit_month'],
            'sms_revenue_today' => $this['sms_revenue_today'],
            'sms_revenue_week' => $this['sms_revenue_week'],
            'sms_revenue_month' => $this['sms_revenue_month'],
            'sms_profit_today' => $this['sms_profit_today'],
            'sms_profit_week' => $this['sms_profit_week'],
            'sms_profit_month' => $this['sms_profit_month'],
            'smm_revenue_today' => $this['smm_revenue_today'],
            'smm_revenue_week' => $this['smm_revenue_week'],
            'smm_revenue_month' => $this['smm_revenue_month'],
            'smm_profit_today' => $this['smm_profit_today'],
            'smm_profit_week' => $this['smm_profit_week'],
            'smm_profit_month' => $this['smm_profit_month'],
            'total_withdrawals' => $this['total_withdrawals'],
        ];
    }
}
