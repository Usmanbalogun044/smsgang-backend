<?php

namespace App\Models;

use App\Enums\TwilioSubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TwilioNumberSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'provider',
        'twilio_account_sid',
        'twilio_number_sid',
        'phone_number_e164',
        'country_code',
        'capabilities',
        'monthly_price_ngn',
        'provider_monthly_price_usd',
        'exchange_rate_used',
        'effective_exchange_rate',
        'global_markup_type_used',
        'global_markup_value_used',
        'twilio_markup_value_used',
        'estimated_cost_ngn',
        'profit_amount',
        'auto_renew',
        'status',
        'started_at',
        'expires_at',
        'next_renewal_at',
        'grace_until',
        'cancelled_at',
        'released_at',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'provider_payload' => 'array',
            'monthly_price_ngn' => 'decimal:2',
            'provider_monthly_price_usd' => 'decimal:4',
            'exchange_rate_used' => 'decimal:4',
            'effective_exchange_rate' => 'decimal:4',
            'global_markup_value_used' => 'decimal:4',
            'twilio_markup_value_used' => 'decimal:4',
            'estimated_cost_ngn' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'auto_renew' => 'boolean',
            'status' => TwilioSubscriptionStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'next_renewal_at' => 'datetime',
            'grace_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TwilioMessage::class);
    }
}
