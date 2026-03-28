<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'provider_code',
        'provider_iso',
        'provider_prefix',
        'provider_name_ru',
        'provider_capabilities',
        'provider_payload',
        'last_synced_at',
        'flag',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'provider_iso' => 'array',
            'provider_prefix' => 'array',
            'provider_capabilities' => 'array',
            'provider_payload' => 'array',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function servicePrices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(Activation::class);
    }
}
