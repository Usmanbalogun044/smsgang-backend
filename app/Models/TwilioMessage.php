<?php

namespace App\Models;

use App\Enums\TwilioMessageDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwilioMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'twilio_number_subscription_id',
        'message_sid',
        'direction',
        'status',
        'from_number',
        'to_number',
        'body',
        'segments',
        'provider_cost_usd',
        'charged_amount_ngn',
        'currency',
        'sent_at',
        'delivered_at',
        'received_at',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'direction' => TwilioMessageDirection::class,
            'segments' => 'integer',
            'provider_cost_usd' => 'decimal:6',
            'charged_amount_ngn' => 'decimal:2',
            'provider_payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TwilioNumberSubscription::class, 'twilio_number_subscription_id');
    }
}
