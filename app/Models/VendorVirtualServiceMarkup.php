<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorVirtualServiceMarkup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'country_id',
        'markup_type',
        'markup_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:4',
            'is_active' => 'boolean',
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
}
