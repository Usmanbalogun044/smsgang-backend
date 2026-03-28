<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicePriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'service' => new ServiceResource($this->whenLoaded('service')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'provider_price' => $this->when($isAdmin, $this->provider_price),
            'available_count' => $this->when($isAdmin, $this->available_count),
            'operator_count' => $this->when($isAdmin, $this->operator_count),
            'provider_payload' => $this->when($isAdmin, $this->provider_payload),
            'last_seen_at' => $this->when($isAdmin, $this->last_seen_at),
            'markup_type' => $this->when($isAdmin, $this->markup_type?->value),
            'markup_value' => $this->when($isAdmin, $this->markup_value),
            'final_price' => $this->final_price,
            'is_active' => $this->is_active,
        ];
    }
}
