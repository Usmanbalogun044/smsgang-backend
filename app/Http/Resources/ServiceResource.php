<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'provider_service_code' => $this->when($isAdmin, $this->provider_service_code),
            'provider_category' => $this->when($isAdmin, $this->provider_category),
            'provider_qty' => $this->when($isAdmin, $this->provider_qty),
            'provider_base_price' => $this->when($isAdmin, $this->provider_base_price),
            'provider_payload' => $this->when($isAdmin, $this->provider_payload),
            'last_synced_at' => $this->when($isAdmin, $this->last_synced_at),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
