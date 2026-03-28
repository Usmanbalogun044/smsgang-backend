<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'gateway'           => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'amount'            => $this->amount,
            'currency'          => $this->currency,
            'status'            => $this->status,
            'description'       => $this->description,
            'verified_at'       => $this->verified_at,
            'order_id'          => $this->order_id,
            // Admin only: expose user and raw gateway response
            'user'              => $this->when(
                $request->user()?->role?->value === 'admin',
                new UserResource($this->whenLoaded('user'))
            ),
            'gateway_response'  => $this->when(
                $request->user()?->role?->value === 'admin',
                $this->gateway_response
            ),
            'ip_address'        => $this->when(
                $request->user()?->role?->value === 'admin',
                $this->ip_address
            ),
            'created_at'        => $this->created_at,
        ];
    }
}
