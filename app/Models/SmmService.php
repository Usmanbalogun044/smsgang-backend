<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmmService extends Model
{
    use HasFactory;

    protected $fillable = [
        'crestpanel_service_id',
        'name',
        'category',
        'type',
        'rate',
        'min',
        'max',
        'refill',
        'cancel',
        'provider_payload',
        'last_synced_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'refill' => 'boolean',
            'cancel' => 'boolean',
            'provider_payload' => 'array',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SmmServicePrice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SmmOrder::class);
    }

    public function getActivePrice(): ?SmmServicePrice
    {
        return $this->prices()->where('is_active', true)->first();
    }
}
