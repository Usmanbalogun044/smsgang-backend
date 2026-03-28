<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            // ServiceSeeder::class,
            // CountrySeeder::class,
        ]);

        // Seed default global settings
        \App\Models\Setting::set('global_markup_fixed', 150);
        \App\Models\Setting::set('global_markup_type', 'fixed');
        \App\Models\Setting::set('exchange_rate_usd_ngn', 1600.0);
    }
}
