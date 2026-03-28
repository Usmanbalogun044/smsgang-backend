<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmmServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'smm_service_id',
        'markup_type',
        'markup_value',
        'final_price',
        'last_synced_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:4',
            'final_price' => 'decimal:2',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SmmService::class);
    }
}
