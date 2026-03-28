<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'reference',
        'gateway',
        'gateway_reference',
        'amount',
        'currency',
        'status',
        'description',
        'ip_address',
        'user_agent',
        'gateway_response',
        'verified_at',
        'operation_type',
        'smm_order_id',
        'type',
        'wallet_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'gateway_response' => 'array',
            'verified_at'      => 'datetime',
            'metadata'         => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
