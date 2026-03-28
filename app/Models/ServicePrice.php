<?php

namespace App\Models;

use App\Enums\MarkupType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'country_id',
        'provider_price',
        'available_count',
        'operator_count',
        'provider_payload',
        'last_seen_at',
        'markup_type',
        'markup_value',
        'final_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'provider_price' => 'decimal:2',
            'available_count' => 'integer',
            'operator_count' => 'integer',
            'provider_payload' => 'array',
            'last_seen_at' => 'datetime',
            'markup_value' => 'decimal:2',
            'final_price' => 'decimal:2',
            'markup_type' => MarkupType::class,
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function calculateFinalPrice(): float
    {
        return match ($this->markup_type) {
            MarkupType::Fixed => round((float) $this->provider_price + (float) $this->markup_value, 2),
            MarkupType::Percent => round((float) $this->provider_price * (1 + (float) $this->markup_value / 100), 2),
        };
    }
}
