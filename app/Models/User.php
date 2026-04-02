<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
        'role',
        'status',
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

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
