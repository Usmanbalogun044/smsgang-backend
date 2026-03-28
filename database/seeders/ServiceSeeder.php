<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Telegram', 'provider_service_code' => 'telegram'],
            ['name' => 'WhatsApp', 'provider_service_code' => 'whatsapp'],
            ['name' => 'Google', 'provider_service_code' => 'google'],
            ['name' => 'TikTok', 'provider_service_code' => 'tiktok'],
            ['name' => 'Instagram', 'provider_service_code' => 'instagram'],
            ['name' => 'Facebook', 'provider_service_code' => 'facebook'],
            ['name' => 'Twitter', 'provider_service_code' => 'twitter'],
            ['name' => 'Discord', 'provider_service_code' => 'discord'],
            ['name' => 'Snapchat', 'provider_service_code' => 'snapchat'],
            ['name' => 'Netflix', 'provider_service_code' => 'netflix'],
            ['name' => 'Amazon', 'provider_service_code' => 'amazon'],
            ['name' => 'Microsoft', 'provider_service_code' => 'microsoft'],
            ['name' => 'Yahoo', 'provider_service_code' => 'yahoo'],
            ['name' => 'LinkedIn', 'provider_service_code' => 'linkedin'],
            ['name' => 'Uber', 'provider_service_code' => 'uber'],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(
                ['provider_service_code' => $service['provider_service_code']],
                ['name' => $service['name'], 'slug' => str($service['name'])->slug()]
            );
        }
    }
}
