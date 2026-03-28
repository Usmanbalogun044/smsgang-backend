<?php

use App\Http\Controllers\Api\ActivationController;
use App\Http\Controllers\Api\Admin\AdminActivationController;
use App\Http\Controllers\Api\Admin\AdminCountryController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminPricingController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminSmmOrderController;
use App\Http\Controllers\Api\Admin\AdminSmmServiceController;
use App\Http\Controllers\Api\Admin\AdminSmmSettingsController;
use App\Http\Controllers\Api\Admin\AdminTransactionController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\WithdrawalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LendoverifyWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SmmOrderController;
use App\Http\Controllers\Api\SmmServiceController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public catalog routes
Route::middleware('throttle:api')->group(function () {
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/countries', [ServiceController::class, 'countries']);
    Route::get('/services/{service}/countries', [ServiceController::class, 'countriesForService']);
    Route::get('/countries/{country}/services', [ServiceController::class, 'servicesForCountry']);
});

// Webhook (no auth, no CSRF)
Route::post('/webhooks/lendoverify', [LendoverifyWebhookController::class, 'handle'])
    ->middleware('throttle:webhook');

// Authenticated user routes
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    // Activations
    Route::post('/activations/buy', [ActivationController::class, 'buy'])
        ->middleware('throttle:buy');
    Route::get('/activations/verify/{reference}', [ActivationController::class, 'verifyPaymentByReference']);
    Route::post('/activations/{order}/verify-payment', [ActivationController::class, 'verifyPayment']);
    Route::get('/activations', [ActivationController::class, 'index']);
    Route::get('/activations/{activation}', [ActivationController::class, 'show']);
    Route::get('/activations/{activation}/check-sms', [ActivationController::class, 'checkSms'])
        ->middleware('throttle:sms-check');
    Route::post('/activations/{activation}/cancel', [ActivationController::class, 'cancel']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index']);

    // Wallet
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'getBalance']);
        Route::post('/fund', [WalletController::class, 'fund']);
        Route::post('/verify-funding', [WalletController::class, 'verifyFunding']);
        Route::get('/transactions', [WalletController::class, 'getTransactions']);
        Route::get('/history', [WalletController::class, 'getTransactions']); // alias
    });

    // SMM Services & Orders
    Route::prefix('smm')->group(function () {
        Route::get('/services', [SmmServiceController::class, 'index']);
        Route::get('/services/{service}', [SmmServiceController::class, 'show']);
        Route::post('/orders', [SmmOrderController::class, 'store']);
        Route::get('/orders', [SmmOrderController::class, 'index']);
        Route::get('/orders/{order}', [SmmOrderController::class, 'show']);
    });
});

// Admin routes
Route::middleware(['auth:sanctum', 'active', 'admin', 'throttle:api'])
    ->prefix('admin')
    ->group(function () {
        // Services
        Route::get('/services', [AdminServiceController::class, 'index']);
        Route::post('/services/{service}/toggle', [AdminServiceController::class, 'toggle']);

        // Countries
        Route::get('/countries', [AdminCountryController::class, 'index']);
        Route::post('/countries/{country}/toggle', [AdminCountryController::class, 'toggle']);

        // Pricing
        Route::get('/prices', [AdminPricingController::class, 'index']);
        Route::put('/prices/{servicePrice}', [AdminPricingController::class, 'update']);
        Route::post('/prices/sync', [AdminPricingController::class, 'sync']);

        // Activations
        Route::get('/activations', [AdminActivationController::class, 'index']);
        Route::post('/activations/{activation}/expire', [AdminActivationController::class, 'expire']);
        Route::get('/stats', [AdminActivationController::class, 'stats']);

        // Orders
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);

        // Users
        Route::get('/users/stats', [AdminUserController::class, 'stats']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{user}', [AdminUserController::class, 'update']);

        // Settings (global markup + exchange rate)
        Route::get('/settings', [AdminSettingsController::class, 'show']);
        Route::put('/settings', [AdminSettingsController::class, 'update']);

        // Withdrawals
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
        Route::delete('/withdrawals/{withdrawal}', [WithdrawalController::class, 'destroy']);

        // Transactions
        Route::get('/transactions', [AdminTransactionController::class, 'index']);
        Route::get('/transactions/{transaction}', [AdminTransactionController::class, 'show']);

        // SMM Services
        Route::get('/smm/services', [AdminSmmServiceController::class, 'index']);
        Route::put('/smm/services/{service}', [AdminSmmServiceController::class, 'toggle']);
        Route::post('/smm/services/sync', [AdminSmmServiceController::class, 'sync']);

        // SMM Settings
        Route::get('/smm/settings', [AdminSmmSettingsController::class, 'index']);
        Route::put('/smm/settings', [AdminSmmSettingsController::class, 'update']);
        Route::put('/smm/services/{serviceId}/markup', [AdminSmmSettingsController::class, 'updateServiceMarkup']);

        // SMM Orders
        Route::get('/smm/orders', [AdminSmmOrderController::class, 'index']);
        Route::get('/smm/orders/{order}', [AdminSmmOrderController::class, 'show']);
    });
