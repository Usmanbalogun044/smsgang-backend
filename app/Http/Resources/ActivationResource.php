<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'selected_operator' => $this->order?->selected_operator,
            'phone_number' => $this->phone_number,
            'sms_code' => $this->sms_code,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'provider_operator' => $this->provider_operator,
            'provider_activation_id' => $this->when($isAdmin, $this->provider_activation_id),
            'service_id' => $this->service_id,
            'country_id' => $this->country_id,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'service' => new ServiceResource($this->whenLoaded('service')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'order_user' => $this->when($isAdmin && $this->order?->user, fn () => [
                'id' => $this->order->user->id,
                'name' => $this->order->user->name,
                'email' => $this->order->user->email,
            ]),
        ];
    }
}
