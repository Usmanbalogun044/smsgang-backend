<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whatsapp_template_id',
        'message_sid',
        'direction',
        'status',
        'from_number',
        'to_number',
        'template_variables',
        'unit_price_ngn',
        'quantity',
        'charged_amount_ngn',
        'provider_cost_value',
        'provider_cost_currency',
        'provider_cost_ngn_estimate',
        'fx_rate_used',
        'profit_amount_ngn',
        'billing_status',
        'billing_reference',
        'error_code',
        'error_message',
        'provider_payload',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'template_variables' => 'array',
            'provider_payload' => 'array',
            'unit_price_ngn' => 'decimal:2',
            'charged_amount_ngn' => 'decimal:2',
            'provider_cost_value' => 'decimal:6',
            'provider_cost_ngn_estimate' => 'decimal:2',
            'fx_rate_used' => 'decimal:4',
            'profit_amount_ngn' => 'decimal:2',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }
}
