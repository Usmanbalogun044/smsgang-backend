<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'country_id',
        'selected_operator',
        'price',
        'provider_price_usd',
        'exchange_rate_used',
        'effective_exchange_rate',
        'global_markup_type_used',
        'global_markup_value_used',
        'estimated_cost_ngn',
        'profit_amount',
        'payment_reference',
        'lendoverify_checkout_url',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'provider_price_usd' => 'decimal:4',
            'exchange_rate_used' => 'decimal:4',
            'effective_exchange_rate' => 'decimal:4',
            'global_markup_value_used' => 'decimal:4',
            'estimated_cost_ngn' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'status' => OrderStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function activation(): HasOne
    {
        return $this->hasOne(Activation::class);
    }
}
