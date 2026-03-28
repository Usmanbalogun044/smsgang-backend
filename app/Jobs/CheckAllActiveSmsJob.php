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

class CheckAllActiveSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        $startTime = microtime(true);
        
        try {
            // Find all activations that are currently active (waiting for SMS)
            $activations = Activation::where('status', ActivationStatus::WaitingSms->value)
                ->where('expires_at', '>', now())
                ->with(['service', 'country', 'order.user'])
                ->get();

            $totalCount = $activations->count();

            foreach ($activations as $activation) {
                // Dispatch the individual checker for this activation
                CheckSmsJob::dispatch($activation->id);
            }

            $duration = microtime(true) - $startTime;
            
            Log::channel('activity')->info(
                "✅ SMS Check Job: Checked {$totalCount} active session(s) in " . 
                number_format($duration, 2) . "s"
            );
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::channel('activity')->error(
                "❌ SMS Check Job Failed: {$e->getMessage()} ({$duration}s)"
            );
            throw $e;
        }
    }
}

