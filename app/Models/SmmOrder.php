<?php

namespace App\Models;

use App\Enums\SmmOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmmOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'smm_service_id',
        'crestpanel_order_id',
        'link',
        'quantity',
        'runs',
        'interval',
        'comments',
        'price_per_unit',
        'total_units',
        'total_cost_ngn',
        'charge_ngn',
        'exchange_rate_used',
        'markup_type_used',
        'markup_value_used',
        'provider_payload',
        'status',
        'tracking_auto_refill_enabled',
        'tracking_initial_count',
        'tracking_current_count',
        'tracking_last_completed_quantity',
        'tracking_drop_detected_quantity',
        'tracking_refilled_quantity',
        'tracking_outstanding_drop_quantity',
        'tracking_last_drop_at',
        'tracking_last_refill_at',
        'tracking_check_6h_at',
        'tracking_check_24h_at',
        'tracking_check_72h_at',
        'tracking_last_status_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => 'decimal:2',
            'total_cost_ngn' => 'decimal:2',
            'charge_ngn' => 'decimal:2',
            'exchange_rate_used' => 'decimal:4',
            'markup_value_used' => 'decimal:4',
            'provider_payload' => 'array',
            'status' => SmmOrderStatus::class,
            'tracking_auto_refill_enabled' => 'boolean',
            'tracking_initial_count' => 'integer',
            'tracking_current_count' => 'integer',
            'tracking_last_completed_quantity' => 'integer',
            'tracking_drop_detected_quantity' => 'integer',
            'tracking_refilled_quantity' => 'integer',
            'tracking_outstanding_drop_quantity' => 'integer',
            'tracking_last_drop_at' => 'datetime',
            'tracking_last_refill_at' => 'datetime',
            'tracking_check_6h_at' => 'datetime',
            'tracking_check_24h_at' => 'datetime',
            'tracking_check_72h_at' => 'datetime',
            'tracking_last_status_checked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SmmService::class, 'smm_service_id');
    }
}
