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
        try {
            $activation = Activation::find($this->activationId);

            if (! $activation) {
                return;
            }

            if ($activation->isTerminal()) {
                return;
            }

            if ($activation->isExpired()) {
                $activationService->expireActivation($activation);
                return;
            }

            $smsCode = $activationService->checkForSms($activation);

            if ($smsCode) {
                $activationService->completeActivation($activation->fresh());
                return;
            }

            if ($this->attempts() < $this->tries) {
                self::dispatch($this->activationId)->delay(now()->addSeconds(5));
            } else {
                $activationService->expireActivation($activation);
            }

        } catch (\Exception $e) {
            Log::channel('activity')->error('SMS check job failed', [
                'activation_id' => $this->activationId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }
}
