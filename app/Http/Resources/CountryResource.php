<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'provider_code' => $this->when($isAdmin, $this->provider_code),
            'provider_name_ru' => $this->when($isAdmin, $this->provider_name_ru),
            'provider_iso' => $this->when($isAdmin, $this->provider_iso),
            'provider_prefix' => $this->when($isAdmin, $this->provider_prefix),
            'provider_capabilities' => $this->when($isAdmin, $this->provider_capabilities),
            'provider_payload' => $this->when($isAdmin, $this->provider_payload),
            'last_synced_at' => $this->when($isAdmin, $this->last_synced_at),
            'service_prices_count' => $this->when($isAdmin, $this->whenCounted('servicePrices')),
            'flag' => $this->flag,
            'is_active' => $this->is_active,
        ];
    }
}
