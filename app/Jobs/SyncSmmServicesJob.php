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
        try {
            $crestPanelService = new CrestPanelService();
            $smmPricingService = new SmmPricingService();

            // Fetch all services from CrestPanel
            $services = $crestPanelService->getServices();

            if (empty($services)) {
                return;
            }

            // Sync services and pricing
            $smmPricingService->syncServices($services);
        } catch (\Exception $e) {
            Log::channel('activity')->error('SMM services sync failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
