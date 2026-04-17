<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\DeveloperApiKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'google_avatar_url',
        'role',
        'status',
        'has_completed_onboarding',
        'onboarding_completed_at',
        'vendor_virtual_markup_type',
        'vendor_virtual_markup_value',
        'vendor_smm_markup_type',
        'vendor_smm_markup_value',
        'is_online',
        'last_login_ip',
        'last_user_agent',
        'last_login_at',
        'last_seen_at',
        'last_logout_at',
    ];

    protected $attributes = [
        'role'   => 'user',
        'status' => 'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'vendor_virtual_markup_value' => 'decimal:4',
            'vendor_smm_markup_value' => 'decimal:4',
            'has_completed_onboarding' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'is_online' => 'boolean',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_logout_at' => 'datetime',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function getBalance(): string
    {
        $wallet = $this->wallet;
        return $wallet ? (string) $wallet->balance : '0.00';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(UserLoginActivity::class);
    }

    public function twilioSubscriptions(): HasMany
    {
        return $this->hasMany(TwilioNumberSubscription::class);
    }

    public function twilioMessages(): HasMany
    {
        return $this->hasMany(TwilioMessage::class);
    }

    public function developerApiKeys(): HasMany
    {
        return $this->hasMany(DeveloperApiKey::class);
    }

    public function vendorVirtualServiceMarkups(): HasMany
    {
        return $this->hasMany(VendorVirtualServiceMarkup::class);
    }

    public function vendorSmmServiceMarkups(): HasMany
    {
        return $this->hasMany(VendorSmmServiceMarkup::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isVendor(): bool
    {
        return $this->role === UserRole::Vendor;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
