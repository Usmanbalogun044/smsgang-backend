<?php

namespace App\Providers;

use App\Models\Activation;
use App\Services\SmsProviders\FiveSimProvider;
use App\Services\SmsProviders\ProviderInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderInterface::class, FiveSimProvider::class);
    }

    public function boot(): void
    {
        // Backward compatibility: accept activation references by either
        // activation id or order id so older clients don't break.
        Route::bind('activation', function ($value) {
            return Activation::query()
                ->whereKey($value)
                ->orWhere('order_id', $value)
                ->firstOrFail();
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(4)->by(strtolower($request->input('email', '')).'|'.$request->ip());
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinute(6)->by(strtolower($request->input('email', '')).'|'.$request->ip());
        });

        RateLimiter::for('otp-resend', function (Request $request) {
            return Limit::perMinutes(10, 3)->by(strtolower($request->input('email', '')).'|'.$request->ip());
        });

        RateLimiter::for('buy', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sms-check', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('whatsapp-send', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
