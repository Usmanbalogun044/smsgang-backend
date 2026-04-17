<?php

use App\Http\Controllers\Api\ActivationController;
use App\Http\Controllers\Api\DeveloperApiKeyController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SmmOrderController;
use App\Http\Controllers\Api\SmmServiceController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TwilioSubscriptionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

// User routes for managing API keys (authenticated with Sanctum)
Route::middleware(['auth:sanctum', 'active', 'track.activity', 'throttle:api'])->group(function () {
    // Developer API keys CRUD
    Route::get('/api-keys', [DeveloperApiKeyController::class, 'index']);
    Route::post('/api-keys', [DeveloperApiKeyController::class, 'store']);
    Route::post('/api-keys/regenerate', [DeveloperApiKeyController::class, 'regenerate']);
    Route::delete('/api-keys/{developerApiKey}', [DeveloperApiKeyController::class, 'destroy'])
        ->whereNumber('developerApiKey');
});

// Developer API routes (API key auth)
Route::prefix('v1')
    ->middleware(['developer.key'])
    ->group(function () {
        // ========== Service Catalog ==========
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/countries', [ServiceController::class, 'countries']);
        Route::get('/services/{service}/countries', [ServiceController::class, 'countriesForService']);
        Route::get('/services/{service}/countries/{country}/operators', [ServiceController::class, 'operatorsForServiceCountry']);
        Route::get('/countries/{country}/services', [ServiceController::class, 'servicesForCountry']);

        // ========== SMS Activations ==========
        Route::post('/activations/buy', [ActivationController::class, 'buy']);
        Route::get('/activations', [ActivationController::class, 'index']);
        
        Route::get('/activations/{activation}', [ActivationController::class, 'show']);
        Route::get('/activations/{activation}/check-sms', [ActivationController::class, 'checkSms']);
        Route::post('/activations/{activation}/cancel', [ActivationController::class, 'cancel']);

        // ========== SMS Orders ==========
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        // ========== SMM (Social Media Marketing) ==========
        Route::prefix('smm')->group(function () {
            // SMM Services catalog
            Route::get('/services', [SmmServiceController::class, 'index']);
            Route::get('/services/{service}', [SmmServiceController::class, 'show']);
            
            // SMM Orders
            Route::post('/orders', [SmmOrderController::class, 'store']);
            Route::get('/orders', [SmmOrderController::class, 'index']);
            Route::get('/orders/{order}', [SmmOrderController::class, 'show']);
        });

        // ========== Monthly Communication Numbers (Twilio) ==========
        Route::prefix('monthly-numbers')->group(function () {
            Route::get('/inventory', [TwilioSubscriptionController::class, 'inventory']);
            Route::post('/purchase', [TwilioSubscriptionController::class, 'purchase']);
            Route::get('/subscriptions', [TwilioSubscriptionController::class, 'index']);
            Route::get('/subscriptions/{subscription}', [TwilioSubscriptionController::class, 'show']);
            Route::patch('/subscriptions/{subscription}/auto-renew', [TwilioSubscriptionController::class, 'updateAutoRenew']);
            Route::get('/subscriptions/{subscription}/messages', [TwilioSubscriptionController::class, 'messages']);
            Route::post('/subscriptions/{subscription}/messages', [TwilioSubscriptionController::class, 'sendMessage']);
        });

        // ========== Wallet Management ==========
        Route::prefix('wallet')->group(function () {
            Route::get('/balance', [WalletController::class, 'getBalance']);
            Route::get('/transactions', [WalletController::class, 'getTransactions']);
            Route::get('/history', [WalletController::class, 'getTransactions']); // alias
        });

        // ========== Transactions ==========
        Route::get('/transactions', [TransactionController::class, 'index']);
    });
