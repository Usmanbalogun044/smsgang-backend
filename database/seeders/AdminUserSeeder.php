<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@smsgang.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ]
        );
    }
}
