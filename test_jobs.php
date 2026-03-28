<?php

require 'bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\CheckAllActiveSmsJob;
use App\Jobs\SyncAllPricingJob;

echo "=== TESTING JOBS ===\n\n";

// Test CheckAllActiveSmsJob
echo "1?????? Testing CheckAllActiveSmsJob...\n";
try {
    $job = new CheckAllActiveSmsJob();
    $job->handle();
    echo "??? SUCCESS\n\n";
} catch (\Throwable $e) {
    echo "??? ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

// Test SyncAllPricingJob
echo "2?????? Testing SyncAllPricingJob...\n";
try {
    $job = new SyncAllPricingJob();
    $job->handle(
        app(\App\Services\PricingService::class),
        app(\App\Services\ExchangeRateService::class)
    );
    echo "??? SUCCESS\n\n";
} catch (\Throwable $e) {
    echo "??? ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

