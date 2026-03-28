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
use Carbon\Carbon;

class ExpireActivationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $startTime = microtime(true);
        $startDate = Carbon::now();
        
        Log::channel('activity')->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::channel('activity')->info('🔔 EXPIRE ACTIVATIONS JOB STARTED');
        Log::channel('activity')->info("⏰ Start Time: {$startDate->format('Y-m-d H:i:s')}");
        Log::channel('activity')->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        try {
            // Get count of expirable activations before update
            $expiringCount = Activation::where('expires_at', '<', now())
                ->whereNotIn('status', [
                    ActivationStatus::Completed->value,
                    ActivationStatus::Expired->value,
                    ActivationStatus::Cancelled->value,
                ])
                ->count();
            
            Log::channel('activity')->info("📊 Found {$expiringCount} activation(s) to expire");
            
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
                
                foreach ($expiring as $activation) {
                    Log::channel('activity')->info(
                        "   • Expiring Activation #{$activation->id} | " .
                        "User: {$activation->order->user->name} | " .
                        "Service: {$activation->service->name} | " .
                        "Country: {$activation->country->name} | " .
                        "Status: {$activation->status} | " .
                        "Expired At: {$activation->expires_at->format('Y-m-d H:i:s')}"
                    );
                }
            }
            
            // Perform the expiration update
            $updatedCount = Activation::where('expires_at', '<', now())
                ->whereNotIn('status', [
                    ActivationStatus::Completed->value,
                    ActivationStatus::Expired->value,
                    ActivationStatus::Cancelled->value,
                ])
                ->update(['status' => ActivationStatus::Expired->value]);
            
            Log::channel('activity')->info("✅ Successfully expired {$updatedCount} activation(s)");
            
            $duration = microtime(true) - $startTime;
            $endDate = Carbon::now();
            
            Log::channel('activity')->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::channel('activity')->info("📈 Summary:");
            Log::channel('activity')->info("   • Processed: {$expiringCount} activation(s)");
            Log::channel('activity')->info("   • Updated: {$updatedCount} record(s)");
            Log::channel('activity')->info("   • Duration: " . number_format($duration, 2) . " second(s)");
            Log::channel('activity')->info("   • End Time: {$endDate->format('Y-m-d H:i:s')}");
            Log::channel('activity')->info("✨ EXPIRE ACTIVATIONS JOB COMPLETED SUCCESSFULLY");
            Log::channel('activity')->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::channel('activity')->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::channel('activity')->error('❌ EXPIRE ACTIVATIONS JOB FAILED');
            Log::channel('activity')->error("Error: {$e->getMessage()}");
            Log::channel('activity')->error("Duration: " . number_format($duration, 2) . " second(s)");
            Log::channel('activity')->error("File: {$e->getFile()}");
            Log::channel('activity')->error("Line: {$e->getLine()}");
            Log::channel('activity')->error("Stack: {$e->getTraceAsString()}");
            Log::channel('activity')->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            throw $e;
        }
    }
}

