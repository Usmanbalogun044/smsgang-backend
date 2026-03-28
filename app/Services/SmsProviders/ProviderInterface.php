<?php

namespace App\Services\SmsProviders;

interface ProviderInterface
{
    public function buyNumber(string $product, string $country, ?string $operator = null): array;

    public function checkSms(string $activationId): ?array;

    public function finishActivation(string $activationId): bool;

    public function cancelActivation(string $activationId): bool;

    public function getBalance(): float;
}
