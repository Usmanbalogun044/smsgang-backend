<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorSmmServiceMarkup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'smm_service_id',
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

    public function smmService(): BelongsTo
    {
        return $this->belongsTo(SmmService::class);
    }
}
