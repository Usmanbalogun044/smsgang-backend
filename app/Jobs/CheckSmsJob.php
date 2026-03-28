<?php

namespace App\Jobs;

use App\Enums\ActivationStatus;
use App\Models\Activation;
use App\Services\ActivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $backoff = 5;

    public function __construct(
        private int $activationId,
    ) {}

    public function handle(ActivationService $activationService): void
    {
        $startTime = microtime(true);
        $StartDate = Carbon::now();
        
        Log::channel('activity')->info("┌─ SMS Check Job #{$this->activationId} Started at {$StartDate->format('H:i:s')} ─┐");
        
        try {
            $activation = Activation::find($this->activationId);

            if (! $activation) {
                Log::channel('activity')->warning("├─ ⚠️ Activation #{$this->activationId} not found - Job terminating");
                Log::channel('activity')->info("└─ End ─┘");
                return;
            }

            Log::channel('activity')->info(
                "├─ 📊 Activation Details:" . 
                " | ID: {$activation->id}" .
                " | Status: {$activation->status->value}" . 
                " | Service: {$activation->service->name}" .
                " | Country: {$activation->country->name}" .
                " | Phone: {$activation->phone_number}"
            );

            if ($activation->isTerminal()) {
                Log::channel('activity')->info("├─ ✅ Activation is in terminal state ({$activation->status->value}) - Job terminating");
                Log::channel('activity')->info("└─ End ─┘");
                return;
            }

            $timeRemaining = now()->diffInSeconds($activation->expires_at);
            Log::channel('activity')->info("├─ ⏱️ Time Remaining: {$timeRemaining} seconds");

            if ($activation->isExpired()) {
                Log::channel('activity')->info("├─ ⏰ Activation has expired - Marking as expired");
                $activationService->expireActivation($activation);
                Log::channel('activity')->info("├─ ✓ Activation marked as expired");
                Log::channel('activity')->info("└─ End ─┘");
                return;
            }

            Log::channel('activity')->info("├─ 🔍 Checking for SMS from provider...");
            $smsCode = $activationService->checkForSms($activation);

            if ($smsCode) {
                Log::channel('activity')->info("├─ ✅ SMS CODE RECEIVED!");
                Log::channel('activity')->info("├─ 📝 Code: {$smsCode}");
                Log::channel('activity')->info("├─ 🎉 Completing activation");
                $activationService->completeActivation($activation->fresh());
                Log::channel('activity')->info("├─ ✓ Activation marked as completed");

                Log::channel('activity')->info("├─ 📊 Activation Summary:");
                Log::channel('activity')->info("│  • User: {$activation->order->user->name}");
                Log::channel('activity')->info("│  • Service: {$activation->service->name}");
                Log::channel('activity')->info("│  • Country: {$activation->country->name}");
                Log::channel('activity')->info("│  • Phone: {$activation->phone_number}");
                Log::channel('activity')->info("│  • SMS Code: {$smsCode}");
                
                $duration = microtime(true) - $startTime;
                Log::channel('activity')->info("├─ ⏱️ Job Duration: " . number_format($duration, 2) . " second(s)");
                Log::channel('activity')->info("└─ ✨ COMPLETED SUCCESSFULLY ─┘");
                return;
            }

            Log::channel('activity')->info("├─ ⏳ No SMS yet, retrying...");

            if ($this->attempts() < $this->tries) {
                Log::channel('activity')->info("├─ 🔄 Re-dispatching check (5s delay)");
                self::dispatch($this->activationId)->delay(now()->addSeconds(5));
                $duration = microtime(true) - $startTime;
                Log::channel('activity')->info("├─ ⏱️ Job Duration: " . number_format($duration, 2) . " second(s)");
                Log::channel('activity')->info("└─ End - Job Re-dispatched ─┘");
            } else {
                Log::channel('activity')->warning("├─ ❌ Max attempts reached ({$this->tries})");
                Log::channel('activity')->warning("├─ 📌 Marking activation as expired due to max retries");
                $activationService->expireActivation($activation);
                $duration = microtime(true) - $startTime;
                Log::channel('activity')->info("├─ ⏱️ Job Duration: " . number_format($duration, 2) . " second(s)");
                Log::channel('activity')->info("└─ ❌ MAX ATTEMPTS REACHED ─┘");
            }

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::channel('activity')->error("├─ ❌ Error in SMS check job");
            Log::channel('activity')->error("├─ Error: {$e->getMessage()}");
            Log::channel('activity')->error("├─ File: {$e->getFile()}");
            Log::channel('activity')->error("├─ Line: {$e->getLine()}");
            Log::channel('activity')->error("├─ ⏱️ Duration: " . number_format($duration, 2) . " second(s)");
            Log::channel('activity')->error("└─ ❌ JOB FAILED ─┘");
            throw $e;
        }
    }
}
