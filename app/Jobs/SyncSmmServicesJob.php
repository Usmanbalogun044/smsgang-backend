<?php

namespace App\Jobs;

use App\Services\CrestPanelService;
use App\Services\SmmPricingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSmmServicesJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::channel('activity')->info('Starting SMM services sync');

        try {
            $crestPanelService = new CrestPanelService();
            $smmPricingService = new SmmPricingService();

            // Fetch all services from CrestPanel
            $services = $crestPanelService->getServices();

            if (empty($services)) {
                Log::channel('activity')->warning('No services returned from CrestPanel');
                return;
            }

            // Sync services and pricing
            $results = $smmPricingService->syncServices($services);

            Log::channel('activity')->info('SMM services sync completed', [
                'total_services' => count($services),
                'created' => $results['created'],
                'updated' => $results['updated'],
                'failed' => $results['failed'],
            ]);
        } catch (\Exception $e) {
            Log::channel('activity')->error('SMM services sync failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
