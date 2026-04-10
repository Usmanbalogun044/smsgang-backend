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

            // Track provider statuses plus recently completed orders (for 72h drop checks)
            $pendingStatuses = SmmOrderStatus::providerTracked();
            $orders = SmmOrder::where(function ($query) use ($pendingStatuses) {
                    $query->whereIn('status', $pendingStatuses)
                        ->orWhere(function ($completedScope) {
                            $completedScope->where('status', SmmOrderStatus::Completed->value)
                                ->where('created_at', '>=', now()->subDays(4));
                        });
                })
                ->whereNotNull('crestpanel_order_id')
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

                    $remains = isset($statusData['remains']) && is_numeric($statusData['remains'])
                        ? max(0, (int) $statusData['remains'])
                        : null;
                    $startCount = isset($statusData['start_count']) && is_numeric($statusData['start_count'])
                        ? max(0, (int) $statusData['start_count'])
                        : null;

                    $completedQuantity = is_int($remains)
                        ? max(0, (int) $order->quantity - $remains)
                        : null;

                    $previousCompleted = (int) ($order->tracking_last_completed_quantity ?? 0);
                    $dropDetected = (int) ($order->tracking_drop_detected_quantity ?? 0);
                    $refilled = (int) ($order->tracking_refilled_quantity ?? 0);
                    $outstandingDrop = (int) ($order->tracking_outstanding_drop_quantity ?? 0);

                    $lastDropAt = $order->tracking_last_drop_at;
                    $lastRefillAt = $order->tracking_last_refill_at;

                    if (is_int($completedQuantity)) {
                        if ($completedQuantity < $previousCompleted) {
                            $dropDelta = $previousCompleted - $completedQuantity;
                            $dropDetected += $dropDelta;
                            $outstandingDrop += $dropDelta;
                            $lastDropAt = now();
                        } elseif ($completedQuantity > $previousCompleted && $outstandingDrop > 0) {
                            $increase = $completedQuantity - $previousCompleted;
                            $refillDelta = min($increase, $outstandingDrop);

                            if ($refillDelta > 0) {
                                $refilled += $refillDelta;
                                $outstandingDrop -= $refillDelta;
                                $lastRefillAt = now();
                            }
                        }
                    }

                    $check6hAt = $order->tracking_check_6h_at;
                    $check24hAt = $order->tracking_check_24h_at;
                    $check72hAt = $order->tracking_check_72h_at;
                    if (! $check6hAt && $order->created_at->lte(now()->subHours(6))) {
                        $check6hAt = now();
                    }
                    if (! $check24hAt && $order->created_at->lte(now()->subHours(24))) {
                        $check24hAt = now();
                    }
                    if (! $check72hAt && $order->created_at->lte(now()->subHours(72))) {
                        $check72hAt = now();
                    }

                    $currentCount = null;
                    if (is_int($startCount) && is_int($completedQuantity)) {
                        $currentCount = $startCount + $completedQuantity;
                    }

                    // Update order with latest status
                    $order->update([
                        'status' => $nextStatus,
                        'charge_ngn' => isset($statusData['charge']) 
                            ? (float) $statusData['charge'] 
                            : $order->charge_ngn,
                        'provider_payload' => $statusData,
                        'tracking_initial_count' => $startCount ?? $order->tracking_initial_count,
                        'tracking_current_count' => $currentCount ?? $order->tracking_current_count,
                        'tracking_last_completed_quantity' => is_int($completedQuantity) ? $completedQuantity : $order->tracking_last_completed_quantity,
                        'tracking_drop_detected_quantity' => $dropDetected,
                        'tracking_refilled_quantity' => $refilled,
                        'tracking_outstanding_drop_quantity' => max(0, $outstandingDrop),
                        'tracking_last_drop_at' => $lastDropAt,
                        'tracking_last_refill_at' => $lastRefillAt,
                        'tracking_check_6h_at' => $check6hAt,
                        'tracking_check_24h_at' => $check24hAt,
                        'tracking_check_72h_at' => $check72hAt,
                        'tracking_last_status_checked_at' => now(),
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
