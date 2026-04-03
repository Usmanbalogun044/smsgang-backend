<?php

namespace App\Jobs;

use App\Enums\SmmOrderStatus;
use App\Models\SmmOrder;
use App\Services\CrestPanelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckSmmOrderStatusJob implements ShouldQueue
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

            // Get all orders that are not yet completed
            $pendingStatuses = SmmOrderStatus::providerTracked();
            $orders = SmmOrder::whereIn('status', $pendingStatuses)
                ->get();

            if ($orders->isEmpty()) {
                return;
            }

            $successCount = 0;
            $failCount = 0;

            foreach ($orders as $order) {
                /** @var SmmOrder $order */
                try {
                    $statusData = $crestPanelService->getOrderStatus($order->crestpanel_order_id);

                    if (is_null($statusData)) {
                        $failCount++;
                        continue;
                    }

                    $providerStatus = $statusData['status'] ?? null;
                    $nextStatus = SmmOrderStatus::tryFrom((string) $providerStatus)?->value ?? $order->status;

                    // Update order with latest status
                    $order->update([
                        'status' => $nextStatus,
                        'charge_ngn' => isset($statusData['charge']) 
                            ? (float) $statusData['charge'] 
                            : $order->charge_ngn,
                        'provider_payload' => $statusData,
                        'updated_at' => now(),
                    ]);

                    $successCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to check SMM order status', [
                        'order_id' => $order->id,
                        'crestpanel_order_id' => $order->crestpanel_order_id,
                        'error' => $e->getMessage(),
                    ]);
                    $failCount++;
                }
            }
        } catch (\Exception $e) {
            Log::channel('activity')->error('SMM order status check job failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
