<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'content_sid',
        'body_preview',
        'variables_schema',
        'unit_price_ngn',
        'is_active',
        'provider_status',
        'approval_status',
        'approval_reason',
        'provider_payload',
        'metadata',
        'approval_requested_at',
        'approved_at',
        'last_synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'variables_schema' => 'array',
            'metadata' => 'array',
            'provider_payload' => 'array',
            'unit_price_ngn' => 'decimal:2',
            'is_active' => 'boolean',
            'approval_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(\App\Models\WhatsappMessage::class, 'whatsapp_template_id');
    }
}
