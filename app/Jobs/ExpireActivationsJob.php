<?php

namespace App\Jobs;

use App\Enums\ActivationStatus;
use App\Models\Activation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireActivationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            // Get count of expirable activations before update
            $expiringCount = Activation::where('expires_at', '<', now())
                ->whereNotIn('status', [
                    ActivationStatus::Completed->value,
                    ActivationStatus::Expired->value,
                    ActivationStatus::Cancelled->value,
                ])
                ->count();

            if ($expiringCount > 0) {
                // Get details of activations being expired
                $expiring = Activation::where('expires_at', '<', now())
                    ->whereNotIn('status', [
                        ActivationStatus::Completed->value,
                        ActivationStatus::Expired->value,
                        ActivationStatus::Cancelled->value,
                    ])
                    ->with(['service', 'country', 'order.user'])
                    ->get();
            }

            // Perform the expiration update
            $updatedCount = Activation::where('expires_at', '<', now())
                ->whereNotIn('status', [
                    ActivationStatus::Completed->value,
                    ActivationStatus::Expired->value,
                    ActivationStatus::Cancelled->value,
                ])
                ->update(['status' => ActivationStatus::Expired->value]);

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::channel('activity')->error('Expire activations job failed', [
                'duration_seconds' => round($duration, 2),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }
}

