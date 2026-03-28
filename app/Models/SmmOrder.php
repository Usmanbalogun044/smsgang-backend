<?php

namespace App\Models;

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
