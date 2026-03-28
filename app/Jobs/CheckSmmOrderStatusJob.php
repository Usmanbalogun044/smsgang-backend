<?php

namespace App\Jobs;

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
            $pendingStatuses = ['Pending', 'In progress', 'Partial'];
            $orders = SmmOrder::whereIn('status', $pendingStatuses)
                ->get();

            if ($orders->isEmpty()) {
                return;
            }

            $successCount = 0;
            $failCount = 0;

            foreach ($orders as $order) {
                try {
                    $statusData = $crestPanelService->getOrderStatus($order->crestpanel_order_id);

                    if (is_null($statusData)) {
                        $failCount++;
                        continue;
                    }

                    // Update order with latest status
                    $order->update([
                        'status' => $statusData['status'] ?? $order->status,
                        'charge_ngn' => isset($statusData['charge']) 
                            ? (float) $statusData['charge'] 
                            : $order->charge_ngn,
                        'provider_payload' => $statusData,
                        'updated_at' => now(),
                    ]);

                    $successCount++;

                    // Log if order is completed
                    if (in_array($statusData['status'] ?? null, ['Completed', 'Cancelled', 'Failed'])) {
                        Log::channel('activity')->info('SMM order status changed', [
                            'order_id' => $order->id,
                            'crestpanel_order_id' => $order->crestpanel_order_id,
                            'status' => $statusData['status'],
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to check SMM order status', [
                        'order_id' => $order->id,
                        'crestpanel_order_id' => $order->crestpanel_order_id,
                        'error' => $e->getMessage(),
                    ]);
                    $failCount++;
                }
            }

            if ($successCount > 0 || $failCount > 0) {
                Log::channel('activity')->info('SMM order status check completed', [
                    'total_checked' => count($orders),
                    'success' => $successCount,
                    'failed' => $failCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('activity')->error('SMM order status check job failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
