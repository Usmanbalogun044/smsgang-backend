<?php

namespace App\Models;

use App\Enums\ActivationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activation extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'service_id',
        'country_id',
        'provider',
        'provider_operator',
        'provider_activation_id',
        'phone_number',
        'sms_code',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActivationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ActivationStatus::Completed,
            ActivationStatus::Expired,
            ActivationStatus::Cancelled,
        ]);
    }
}
