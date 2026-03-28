<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['name' => 'Russia', 'code' => 'RU', 'flag' => '🇷🇺'],
            ['name' => 'Ukraine', 'code' => 'UA', 'flag' => '🇺🇦'],
            ['name' => 'Kazakhstan', 'code' => 'KZ', 'flag' => '🇰🇿'],
            ['name' => 'China', 'code' => 'CN', 'flag' => '🇨🇳'],
            ['name' => 'Philippines', 'code' => 'PH', 'flag' => '🇵🇭'],
            ['name' => 'India', 'code' => 'IN', 'flag' => '🇮🇳'],
            ['name' => 'Indonesia', 'code' => 'ID', 'flag' => '🇮🇩'],
            ['name' => 'Malaysia', 'code' => 'MY', 'flag' => '🇲🇾'],
            ['name' => 'Kenya', 'code' => 'KE', 'flag' => '🇰🇪'],
            ['name' => 'USA', 'code' => 'US', 'flag' => '🇺🇸'],
            ['name' => 'United Kingdom', 'code' => 'GB', 'flag' => '🇬🇧'],
            ['name' => 'Netherlands', 'code' => 'NL', 'flag' => '🇳🇱'],
            ['name' => 'Poland', 'code' => 'PL', 'flag' => '🇵🇱'],
            ['name' => 'England', 'code' => 'EN', 'flag' => '🏴'],
            ['name' => 'Nigeria', 'code' => 'NG', 'flag' => '🇳🇬'],
            ['name' => 'Germany', 'code' => 'DE', 'flag' => '🇩🇪'],
            ['name' => 'France', 'code' => 'FR', 'flag' => '🇫🇷'],
            ['name' => 'Brazil', 'code' => 'BR', 'flag' => '🇧🇷'],
            ['name' => 'Mexico', 'code' => 'MX', 'flag' => '🇲🇽'],
            ['name' => 'Thailand', 'code' => 'TH', 'flag' => '🇹🇭'],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                ['name' => $country['name'], 'flag' => $country['flag']]
            );
        }
    }
}
