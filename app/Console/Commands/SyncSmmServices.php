<?php

namespace App\Console\Commands;

use App\Models\SmmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSmmServices extends Command
{
    protected $signature = 'app:sync-smm-services';
    protected $description = 'Sync services from CrestPanel and apply pricing logic';

    public function handle()
    {
        $apiKey = env('CRESTPANEL_API_KEY');
        $apiUrl = 'https://crestpanel.com/api/v2';

        if (!$apiKey) {
            $this->error('CRESTPANEL_API_KEY is not set');
            return;
        }

        $this->info('Fetching services from CrestPanel...');

        try {
            $response = Http::asForm()->post($apiUrl, [
                'key' => $apiKey,
                'action' => 'services',
            ]);

            if ($response->failed()) {
                $this->error('Failed to fetch services: ' . $response->body());
                return;
            }

            $services = $response->json();
            $count = count($services);
            $this->info("Found $count services. Syncing with database...");

            foreach ($services as $service) {
                SmmService::updateOrCreate(
                    ['crestpanel_service_id' => $service['service']],
                    [
                        'name' => $service['name'],
                        'category' => $service['category'],
                        'type' => $service['type'],
                        'rate' => $service['rate'],
                        'min' => $service['min'],
                        'max' => $service['max'],
                        'refill' => $service['refill'] ?? false,
                        'cancel' => $service['cancel'] ?? false,
                        'provider_payload' => $service,
                        'last_synced_at' => now(),
                        'is_active' => true,
                    ]
                );
            }

            $this->info('Sync completed successfully!');
        } catch (\Exception $e) {
            $this->error('Error during sync: ' . $e->getMessage());
            Log::error('SMM Sync Error', ['error' => $e->getMessage()]);
        }
    }
}
