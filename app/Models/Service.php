<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'provider_service_code',
        'provider_category',
        'provider_qty',
        'provider_base_price',
        'provider_payload',
        'last_synced_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'provider_qty' => 'integer',
            'provider_base_price' => 'decimal:4',
            'provider_payload' => 'array',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $lookup = strtolower((string) $value);

        if ($lookup === 'whatapp') {
            $lookup = 'whatsapp';
        }

        $service = $this->newQuery()
            ->where($field ?? 'slug', $lookup)
            ->orWhere('provider_service_code', $lookup)
            ->first();

        if (! $service) {
            throw (new ModelNotFoundException())->setModel(self::class, [$value]);
        }

        return $service;
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
