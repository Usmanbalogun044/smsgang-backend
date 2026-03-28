<?php

use App\Jobs\CheckAllActiveSmsJob;
use App\Jobs\CheckSmmOrderStatusJob;
use App\Jobs\ExpireActivationsJob;
use App\Jobs\SyncAllPricingJob;
use App\Jobs\SyncSmmServicesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clear sync lock every 30 mins to prevent sync deadlocks
Schedule::call(fn() => Cache::forget('sync_in_progress'))
    ->name('clear-sync-lock')
    ->everyThirtyMinutes()
    ->withoutOverlapping(5);

// Sync pricing every hour from 5sim and update exchange rates
Schedule::job(new SyncAllPricingJob())
    ->name('sync-pricing')
    ->hourlyAt(5)
    ->withoutOverlapping(10)
    ->onSuccess(function () {
        Log::channel('activity')->info('✅ Pricing sync completed');
    })
    ->onFailure(function () {
        Log::channel('activity')->error('❌ Pricing sync failed');
    });

// Check all active SMS every 30 seconds for faster OTP delivery
Schedule::job(new CheckAllActiveSmsJob())
    ->name('check-sms')
    ->everyThirtySeconds()
    ->withoutOverlapping(5)
    ->onSuccess(function () {
        Log::channel('activity')->info('✅ SMS check completed');
    })
    ->onFailure(function () {
        Log::channel('activity')->error('❌ SMS check failed');
    });

// Expire old activations every 30 minutes
Schedule::job(new ExpireActivationsJob())
    ->name('expire-activations')
    ->everyThirtyMinutes()
    ->withoutOverlapping(15)
    ->onSuccess(function () {
        Log::channel('activity')->info('✅ Activation expiry check completed');
    })
    ->onFailure(function () {
        Log::channel('activity')->error('❌ Activation expiry check failed');
    });

// Sync SMM services from CrestPanel every hour at minute 10
Schedule::job(new SyncSmmServicesJob())
    ->name('sync-smm-services')
    ->hourlyAt(10)
    ->withoutOverlapping(20)
    ->onSuccess(function () {
        Log::channel('activity')->info('✅ SMM services sync completed');
    })
    ->onFailure(function () {
        Log::channel('activity')->error('❌ SMM services sync failed');
    });

// Check SMM order status every 5 minutes
Schedule::job(new CheckSmmOrderStatusJob())
    ->name('check-smm-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onSuccess(function () {
        Log::channel('activity')->info('✅ SMM order status check completed');
    })
    ->onFailure(function () {
        Log::channel('activity')->error('❌ SMM order status check failed');
    });

// Clean up failed jobs every day at 2 AM
Schedule::command('queue:prune-failed')
    ->name('prune-failed-jobs')
    ->dailyAt('02:00')
    ->withoutOverlapping(30);

// Clean up expired cache every day at 3 AM
Schedule::command('cache:prune-stale-tags')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);
