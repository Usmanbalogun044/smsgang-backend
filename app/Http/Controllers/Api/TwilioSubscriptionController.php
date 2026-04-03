<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TwilioMessage;
use App\Models\TwilioNumberSubscription;
use App\Services\PricingService;
use App\Services\TelegramNotificationService;
use App\Services\TwilioPhoneNumberService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwilioSubscriptionController extends Controller
{
    public function __construct(
        private TwilioPhoneNumberService $twilioService,
        private PricingService $pricingService,
        private WalletService $walletService,
        private TelegramNotificationService $telegramService,
    ) {}

    public function inventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'contains' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $country = strtoupper((string) ($validated['country'] ?? config('services.twilio.default_country', 'US')));
        $contains = (string) ($validated['contains'] ?? '');
        $limit = (int) ($validated['limit'] ?? 20);
        $ttl = (int) config('services.twilio.inventory_cache_ttl', 90);

        $cacheKey = sprintf('twilio:inventory:list:%s:%s:%d', $country, $contains, $limit);

        $items = Cache::remember($cacheKey, $ttl, function () use ($country, $contains, $limit, $ttl) {
            $raw = $this->twilioService->listAvailableLocalNumbers($country, [
                'contains' => $contains !== '' ? $contains : null,
                'limit' => $limit,
                'sms_enabled' => true,
            ]);

            $defaultProviderUsd = (float) Setting::get('twilio_default_monthly_price_usd', config('services.twilio.default_monthly_price_usd', 1.2));
            $normalized = [];

            foreach ($raw as $row) {
                $capabilities = $row['capabilities'] ?? null;
                if (is_array($capabilities) && array_key_exists('sms', $capabilities) && ! (bool) $capabilities['sms']) {
                    continue;
                }

                $providerPriceUsd = (float) ($row['monthly_price'] ?? $row['price'] ?? $defaultProviderUsd);
                if ($providerPriceUsd <= 0) {
                    $providerPriceUsd = $defaultProviderUsd;
                }

                $breakdown = $this->pricingService->calculateTwilioMonthlyBreakdown($providerPriceUsd);
                $phone = (string) ($row['phone_number'] ?? '');
                if ($phone === '') {
                    continue;
                }

                $reservationKey = sha1($country . '|' . $phone . '|' . $providerPriceUsd);
                $reservationCacheKey = 'twilio:inventory:item:' . $reservationKey;

                $reservationPayload = [
                    'country' => $country,
                    'phone_number' => $phone,
                    'friendly_name' => $row['friendly_name'] ?? $phone,
                    'provider_monthly_price_usd' => $providerPriceUsd,
                    'capabilities' => $row['capabilities'] ?? null,
                    'provider_payload' => $row,
                ];

                Cache::put($reservationCacheKey, $reservationPayload, $ttl);

                $normalized[] = [
                    'reservation_key' => $reservationKey,
                    'phone_number' => $phone,
                    'friendly_name' => $row['friendly_name'] ?? $phone,
                    'locality' => $row['locality'] ?? null,
                    'region' => $row['region'] ?? null,
                    'country' => $country,
                    'capabilities' => $capabilities,
                    'monthly_price_ngn' => $breakdown['final_price_ngn'],
                ];
            }

            return $normalized;
        });

        return response()->json([
            'country' => $country,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reservation_key' => ['required', 'string', 'max:64'],
            'auto_renew' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $reservationKey = (string) $validated['reservation_key'];
        $autoRenew = (bool) ($validated['auto_renew'] ?? false);

        $reservation = Cache::get('twilio:inventory:item:' . $reservationKey);
        if (! is_array($reservation)) {
            return response()->json([
                'message' => 'Selected number is no longer available. Please refresh inventory and try again.',
                'error' => 'stale_reservation',
            ], 422);
        }

        $phoneNumber = (string) ($reservation['phone_number'] ?? '');
        $providerMonthlyPriceUsd = (float) ($reservation['provider_monthly_price_usd'] ?? 0);

        if ($phoneNumber === '' || $providerMonthlyPriceUsd <= 0) {
            return response()->json([
                'message' => 'Selected number payload is invalid. Please refresh and try again.',
                'error' => 'invalid_reservation',
            ], 422);
        }

        $lock = Cache::lock('lock:twilio-buy:' . sha1($user->id . '|' . $phoneNumber), 15);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'A purchase is already in progress for this number. Please retry shortly.',
                'error' => 'purchase_in_progress',
            ], 429);
        }

        $incomingNumber = null;
        $debitReference = 'TWILIO_SUB_' . uniqid();

        try {
            // Reserve in Twilio first.
            $incomingNumber = $this->twilioService->purchaseIncomingNumber($phoneNumber);

            $twilioNumberSid = (string) ($incomingNumber['sid'] ?? '');
            if ($twilioNumberSid === '') {
                throw new \RuntimeException('Twilio reservation response is missing SID.');
            }

            $pricing = $this->pricingService->calculateTwilioMonthlyBreakdown($providerMonthlyPriceUsd);
            $sellPriceNgn = (float) $pricing['final_price_ngn'];

            $debitTx = $this->walletService->deductFunds(
                $user,
                $sellPriceNgn,
                $debitReference,
                'Twilio monthly number subscription: ' . $phoneNumber
            );

            if (! $debitTx) {
                $this->twilioService->releaseIncomingNumber($twilioNumberSid);

                return response()->json([
                    'message' => 'Insufficient wallet balance.',
                    'error' => 'insufficient_balance',
                ], 422);
            }

            try {
                $subscription = TwilioNumberSubscription::create([
                    'user_id' => $user->id,
                    'provider' => 'twilio',
                    'twilio_account_sid' => config('services.twilio.account_sid'),
                    'twilio_number_sid' => $twilioNumberSid,
                    'phone_number_e164' => (string) ($incomingNumber['phone_number'] ?? $phoneNumber),
                    'country_code' => $reservation['country'] ?? null,
                    'capabilities' => $reservation['capabilities'] ?? null,
                    'monthly_price_ngn' => $sellPriceNgn,
                    'provider_monthly_price_usd' => $providerMonthlyPriceUsd,
                    'exchange_rate_used' => $pricing['exchange_rate_used'],
                    'effective_exchange_rate' => $pricing['effective_exchange_rate'],
                    'global_markup_type_used' => $pricing['global_markup_type_used'],
                    'global_markup_value_used' => $pricing['global_markup_value_used'],
                    'twilio_markup_value_used' => $pricing['twilio_markup_value_used'],
                    'estimated_cost_ngn' => $pricing['estimated_cost_ngn'],
                    'profit_amount' => $pricing['profit_amount'],
                    'auto_renew' => $autoRenew,
                    'status' => 'active',
                    'started_at' => now(),
                    'expires_at' => now()->addMonth(),
                    'next_renewal_at' => now()->addMonth(),
                    'provider_payload' => $incomingNumber,
                ]);
            } catch (Throwable $persistError) {
                $this->walletService->refundFunds(
                    $user,
                    $sellPriceNgn,
                    'TWILIO_REFUND_' . $debitReference,
                    'Refund for failed Twilio subscription setup'
                );

                $this->twilioService->releaseIncomingNumber($twilioNumberSid);

                throw $persistError;
            }

            $this->telegramService->sendTransactionNotification(
                $user,
                $sellPriceNgn,
                'debit',
                'Twilio monthly number ' . $subscription->phone_number_e164
            );

            return response()->json([
                'message' => 'Monthly private number activated successfully.',
                'subscription' => $this->serializeSubscription($subscription),
                'remaining_balance' => $this->walletService->getBalance($user),
            ], 201);
        } catch (Throwable $e) {
            Log::channel('activity')->error('Twilio monthly purchase failed', [
                'user_id' => $user->id,
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Monthly number purchase failed. Please try again.',
                'error' => 'twilio_purchase_failed',
            ], 503);
        } finally {
            optional($lock)->release();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $subscriptions = TwilioNumberSubscription::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $subscriptions->getCollection()
                ->map(fn (TwilioNumberSubscription $subscription) => $this->serializeSubscription($subscription))
                ->values(),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    public function show(Request $request, TwilioNumberSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        return response()->json([
            'subscription' => $this->serializeSubscription($subscription),
        ]);
    }

    public function updateAutoRenew(Request $request, TwilioNumberSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'auto_renew' => ['required', 'boolean'],
        ]);

        $subscription->update([
            'auto_renew' => (bool) $validated['auto_renew'],
        ]);

        return response()->json([
            'message' => 'Auto-renew preference updated.',
            'subscription' => $this->serializeSubscription($subscription->fresh()),
        ]);
    }

    public function sendMessage(Request $request, TwilioNumberSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1600'],
        ]);

        if (! in_array((string) $subscription->status->value, ['active', 'renewal_due', 'grace'], true)) {
            return response()->json([
                'message' => 'Subscription is not active for messaging.',
                'error' => 'subscription_inactive',
            ], 422);
        }

        $result = $this->twilioService->sendSms(
            $subscription->phone_number_e164,
            (string) $validated['to'],
            (string) $validated['body'],
        );

        $message = TwilioMessage::updateOrCreate(
            ['message_sid' => (string) ($result['sid'] ?? '')],
            [
                'user_id' => $request->user()->id,
                'twilio_number_subscription_id' => $subscription->id,
                'direction' => 'outbound',
                'status' => $result['status'] ?? 'queued',
                'from_number' => $result['from'] ?? $subscription->phone_number_e164,
                'to_number' => $result['to'] ?? $validated['to'],
                'body' => $result['body'] ?? $validated['body'],
                'segments' => (int) ($result['num_segments'] ?? 1),
                'provider_payload' => $result,
                'sent_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'SMS sent successfully.',
            'twilio_message' => $this->serializeMessage($message),
        ], 201);
    }

    public function messages(Request $request, TwilioNumberSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $messages = $subscription->messages()->latest()->paginate(30);

        return response()->json([
            'data' => $messages->getCollection()
                ->map(fn (TwilioMessage $message) => $this->serializeMessage($message))
                ->values(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    private function serializeSubscription(TwilioNumberSubscription $subscription): array
    {
        $status = is_object($subscription->status) && property_exists($subscription->status, 'value')
            ? $subscription->status->value
            : (string) $subscription->status;

        return [
            'id' => $subscription->id,
            'phone_number_e164' => $subscription->phone_number_e164,
            'country_code' => $subscription->country_code,
            'monthly_price_ngn' => (float) $subscription->monthly_price_ngn,
            'status' => $status,
            'auto_renew' => (bool) $subscription->auto_renew,
            'started_at' => optional($subscription->started_at)?->toISOString(),
            'expires_at' => optional($subscription->expires_at)?->toISOString(),
            'next_renewal_at' => optional($subscription->next_renewal_at)?->toISOString(),
            'created_at' => optional($subscription->created_at)?->toISOString(),
        ];
    }

    private function serializeMessage(TwilioMessage $message): array
    {
        $direction = is_object($message->direction) && property_exists($message->direction, 'value')
            ? $message->direction->value
            : (string) $message->direction;

        return [
            'id' => $message->id,
            'direction' => $direction,
            'status' => $message->status,
            'from_number' => $message->from_number,
            'to_number' => $message->to_number,
            'body' => $message->body,
            'created_at' => optional($message->created_at)?->toISOString(),
        ];
    }
}
