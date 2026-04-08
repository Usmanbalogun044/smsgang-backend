<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastSeenAt = $this->last_seen_at;
        $isOnline = (bool) $this->is_online;
        if ($lastSeenAt) {
            $isOnline = $isOnline && $lastSeenAt->gt(now()->subMinutes(10));
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'is_email_verified' => (bool) $this->email_verified_at,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'vendor_virtual_markup_type' => $this->vendor_virtual_markup_type,
            'vendor_virtual_markup_value' => $this->vendor_virtual_markup_value !== null ? (float) $this->vendor_virtual_markup_value : null,
            'vendor_smm_markup_type' => $this->vendor_smm_markup_type,
            'vendor_smm_markup_value' => $this->vendor_smm_markup_value !== null ? (float) $this->vendor_smm_markup_value : null,
            'has_completed_onboarding' => (bool) $this->has_completed_onboarding,
            'onboarding_completed_at' => $this->onboarding_completed_at,
            'balance' => (float) $this->getBalance(),
            'is_online' => $isOnline,
            'last_login_ip' => $this->last_login_ip,
            'last_user_agent' => $this->last_user_agent,
            'last_login_at' => $this->last_login_at,
            'last_seen_at' => $this->last_seen_at,
            'last_logout_at' => $this->last_logout_at,
            'created_at' => $this->created_at,
        ];
    }
}
