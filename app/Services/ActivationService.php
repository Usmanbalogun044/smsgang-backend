<?php

namespace App\Services;

use App\Enums\ActivationStatus;
use App\Enums\MarkupType;
use App\Enums\OrderStatus;
use App\Jobs\CheckSmsJob;
use App\Models\Activation;
use App\Models\Country;
use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\ServicePrice;
use App\Models\User;
use App\Services\SmsProviders\ProviderInterface;
use Illuminate\Support\Facades\Log;

class ActivationService
{
    public function __construct(
        private ProviderInterface $provider,
        private LendoverifyService $lendoverify,
        private PricingService $pricingService,
    ) {}

    public function initiatePurchase(User $user, Service $service, Country $country, string $operator): Order
    {
        // Settings are handled inside PricingService.
        $providerPrice = 0.0;
        $savedPrice = ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->first();

        if ($savedPrice) {
            $operators = is_array($savedPrice->provider_payload) ? $savedPrice->provider_payload : [];
            $operatorInfo = $operators[$operator] ?? null;

            if (is_array($operatorInfo)) {
                $count = (int) ($operatorInfo['count'] ?? 0);
                $cost = (float) ($operatorInfo['cost'] ?? 0);
                if ($count > 0 && $cost > 0) {
                    $providerPrice = $cost;
                }
            }
        }

        if ($providerPrice <= 0) {
            throw new \RuntimeException('Selected operator is unavailable. Please choose another operator.');
        }

        $exchangeRate = (float) Setting::get('exchange_rate_usd_ngn', 1600.0);
        $globalMarkupType = (string) Setting::get('global_markup_type', 'fixed');
        $globalMarkupValue = (float) Setting::get('global_markup_fixed', 150);

        // Keep the same safety factor as PricingService so reported profit aligns with selling price.
        $effectiveExchangeRate = $exchangeRate * 1.05;
        $estimatedCostNgn = round($providerPrice * $effectiveExchangeRate, 2);

        $markupAmount = $globalMarkupType === 'percentage'
            ? round(($estimatedCostNgn * $globalMarkupValue) / 100, 2)
            : round($globalMarkupValue, 2);

        $finalPrice = $this->pricingService->calculateFinalPrice($providerPrice, MarkupType::Fixed, 0);
        $profitAmount = round($finalPrice - $estimatedCostNgn, 2);

        if ($profitAmount < 0) {
            $profitAmount = 0.0;
        }

        $paymentReference = 'SMS_' . uniqid();

        $order = Order::create([
            'user_id'           => $user->id,
            'service_id'        => $service->id,
            'country_id'        => $country->id,
            'selected_operator' => $operator,
            'price'             => $finalPrice,
            'provider_price_usd' => $providerPrice,
            'exchange_rate_used' => $exchangeRate,
            'effective_exchange_rate' => $effectiveExchangeRate,
            'global_markup_type_used' => $globalMarkupType,
            'global_markup_value_used' => $globalMarkupValue,
            'estimated_cost_ngn' => $estimatedCostNgn,
            'profit_amount' => $profitAmount,
            'payment_reference' => $paymentReference,
            'status'            => OrderStatus::Pending,
        ]);

        // Send the clean base URL — Lendoverify will append ?paymentReference=... itself.
        // Do NOT add our own query params here or we'll get a double-? malformed URL.
        $callbackUrl = rtrim((string) config('app.verify_payment_url', config('app.frontend_url', config('app.url')) . '/verify-payment'), '/');

        $payment = $this->lendoverify->initializeTransaction([
            'amount'             => (int) round($finalPrice * 100),
            'customerEmail'      => $user->email,
            'customerName'       => $user->name,
            'paymentReference'   => $paymentReference,
            'paymentDescription' => "SMS Activation: {$service->name} ({$country->name})",
            'redirectUrl'        => $callbackUrl,
        ]);

        $data = $payment['data'] ?? $payment;

        $order->update([
            'lendoverify_checkout_url' => $data['checkout_url'] ?? $data['authorization_url'] ?? null,
            'payment_reference' => $data['paymentReference'] ?? $paymentReference,
        ]);

        return $order->fresh();
    }

    public function processAfterPayment(Order $order): Activation
    {
        $order->update(['status' => OrderStatus::Processing]);

        try {
            $countryCode = $order->country->provider_code ?? strtolower($order->country->name);
            $quotedProviderCost = (float) ($order->provider_price_usd ?? 0);
            $selectedOperator = $order->selected_operator;

            $result = null;
            $buyErrors = [];

            if (! empty($selectedOperator)) {
                try {
                    $attempt = $this->provider->buyNumber(
                        product: $order->service->provider_service_code,
                        country: $countryCode,
                        operator: $selectedOperator,
                    );

                    $reportedProviderCost = $attempt['cost'] ?? $attempt['price'] ?? null;
                    if ($reportedProviderCost !== null && $quotedProviderCost > 0 && (float) $reportedProviderCost > $quotedProviderCost) {
                        try {
                            $this->provider->cancelActivation((string) $attempt['id']);
                        } catch (\Throwable) {
                            // Ignore cancel errors; guard still blocks unsafe activation completion.
                        }

                        throw new \RuntimeException(
                            "Selected operator exceeded quoted provider cost ({$reportedProviderCost} > {$quotedProviderCost})"
                        );
                    }

                    $result = $attempt;
                } catch (\Throwable $selectedOperatorError) {
                    $buyErrors[] = "Operator {$selectedOperator} failed: {$selectedOperatorError->getMessage()}";
                }
            }

            if (! $result) {
                throw new \RuntimeException(
                    'Selected operator is unavailable at buy time. ' .
                    (empty($buyErrors) ? '' : ('Attempts: ' . implode(' | ', $buyErrors)))
                );
            }

            $activation = Activation::create([
                'order_id' => $order->id,
                'service_id' => $order->service_id,
                'country_id' => $order->country_id,
                'provider' => '5sim',
                'provider_operator' => $result['operator'] ?? null,
                'provider_activation_id' => $result['id'],
                'phone_number' => $result['phone'],
                'status' => ActivationStatus::NumberReceived,
                'expires_at' => now()->addMinutes(15),
            ]);

            $order->update(['status' => OrderStatus::Completed]);

            CheckSmsJob::dispatch($activation->id)->delay(now()->addSeconds(5));

            Log::channel('activity')->info('Activation created', [
                'order_id' => $order->id,
                'activation_id' => $activation->id,
                'phone' => $result['phone'],
            ]);

            return $activation;
        } catch (\Exception $e) {
            $order->update(['status' => OrderStatus::Failed]);

            Log::channel('activity')->error('Number purchase failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function checkForSms(Activation $activation): ?string
    {
        try {
            $result = $this->provider->checkSms($activation->provider_activation_id);

            if ($result && ! empty($result['codes'])) {
                $smsCode = implode(', ', $result['codes']);

                $activation->update([
                    'sms_code' => $smsCode,
                    'status' => ActivationStatus::SmsReceived,
                ]);

                return $smsCode;
            }

            if ($activation->status->value === ActivationStatus::NumberReceived->value) {
                $activation->update(['status' => ActivationStatus::WaitingSms]);
            }

            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error checking SMS', [
                'activation_id' => $activation->id,
                'provider_activation_id' => $activation->provider_activation_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function completeActivation(Activation $activation): void
    {
        $this->provider->finishActivation($activation->provider_activation_id);

        $activation->update(['status' => ActivationStatus::Completed]);
    }

    public function cancelActivation(Activation $activation): void
    {
        $this->provider->cancelActivation($activation->provider_activation_id);

        $activation->update(['status' => ActivationStatus::Cancelled]);
    }

    public function expireActivation(Activation $activation): void
    {
        $activation->update(['status' => ActivationStatus::Expired]);
    }
}
