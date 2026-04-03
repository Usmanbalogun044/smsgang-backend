<?php

namespace App\Jobs;

use App\Enums\TwilioSubscriptionStatus;
use App\Models\TwilioNumberSubscription;
use App\Services\TwilioPhoneNumberService;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessTwilioRenewalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function handle(WalletService $walletService, TwilioPhoneNumberService $twilioService): void
    {
        $now = now();

        // 1) Process due auto-renew subscriptions.
        TwilioNumberSubscription::query()
            ->where('auto_renew', true)
            ->whereIn('status', [
                TwilioSubscriptionStatus::Active->value,
                TwilioSubscriptionStatus::RenewalDue->value,
                TwilioSubscriptionStatus::Grace->value,
            ])
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', $now)
            ->chunkById(100, function ($subscriptions) use ($walletService, $now) {
                foreach ($subscriptions as $subscription) {
                    /** @var TwilioNumberSubscription $subscription */
                    $lockKey = 'lock:twilio-renew:' . $subscription->id;
                    $lock = Cache::lock($lockKey, 15);

                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $reference = sprintf('TWILIO_RENEW_%d_%s', $subscription->id, $now->format('Ym'));

                        $debit = $walletService->deductFunds(
                            $subscription->user,
                            (float) $subscription->monthly_price_ngn,
                            $reference,
                            'Twilio monthly renewal: ' . $subscription->phone_number_e164
                        );

                        if (! $debit) {
                            $subscription->update([
                                'status' => TwilioSubscriptionStatus::Grace,
                                'grace_until' => $subscription->grace_until ?? $now->copy()->addDays(3),
                            ]);

                            continue;
                        }

                        $subscription->update([
                            'status' => TwilioSubscriptionStatus::Active,
                            'started_at' => $subscription->started_at ?? $now,
                            'expires_at' => ($subscription->expires_at && $subscription->expires_at->isFuture())
                                ? $subscription->expires_at->copy()->addMonth()
                                : $now->copy()->addMonth(),
                            'next_renewal_at' => ($subscription->next_renewal_at && $subscription->next_renewal_at->isFuture())
                                ? $subscription->next_renewal_at->copy()->addMonth()
                                : $now->copy()->addMonth(),
                            'grace_until' => null,
                        ]);
                    } finally {
                        optional($lock)->release();
                    }
                }
            });

        // 2) Release subscriptions that passed grace/expiry.
        TwilioNumberSubscription::query()
            ->whereIn('status', [
                TwilioSubscriptionStatus::Active->value,
                TwilioSubscriptionStatus::RenewalDue->value,
                TwilioSubscriptionStatus::Grace->value,
            ])
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('grace_until')->where('grace_until', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('auto_renew')->orWhere('auto_renew', false)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $now);
                });
            })
            ->chunkById(100, function ($subscriptions) use ($twilioService, $now) {
                foreach ($subscriptions as $subscription) {
                    /** @var TwilioNumberSubscription $subscription */
                    $lockKey = 'lock:twilio-release:' . $subscription->id;
                    $lock = Cache::lock($lockKey, 15);

                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $released = $twilioService->releaseIncomingNumber($subscription->twilio_number_sid);

                        $subscription->update([
                            'status' => $released ? TwilioSubscriptionStatus::Released : TwilioSubscriptionStatus::Expired,
                            'released_at' => $released ? $now : $subscription->released_at,
                        ]);
                    } finally {
                        optional($lock)->release();
                    }
                }
            });
    }
}
