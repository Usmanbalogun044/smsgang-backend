<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TwilioPhoneNumberService
{
    public function listAvailableLocalNumbers(string $countryCode, array $filters = []): array
    {
        $country = strtoupper(trim($countryCode));
        $limit = max(1, min((int) ($filters['limit'] ?? 20), 50));

        $query = [
            'PageSize' => $limit,
        ];

        if (! empty($filters['contains'])) {
            $query['Contains'] = (string) $filters['contains'];
        }

        if (array_key_exists('sms_enabled', $filters)) {
            $query['SmsEnabled'] = (bool) $filters['sms_enabled'] ? 'true' : 'false';
        }

        if (array_key_exists('voice_enabled', $filters)) {
            $query['VoiceEnabled'] = (bool) $filters['voice_enabled'] ? 'true' : 'false';
        }

        if (array_key_exists('mms_enabled', $filters)) {
            $query['MmsEnabled'] = (bool) $filters['mms_enabled'] ? 'true' : 'false';
        }

        $response = $this->request()->get($this->resourcePath("AvailablePhoneNumbers/{$country}/Local.json"), $query);

        $response->throw();

        return (array) ($response->json('available_phone_numbers') ?? []);
    }

    public function purchaseIncomingNumber(string $phoneNumberE164): array
    {
        $payload = [
            'PhoneNumber' => $phoneNumberE164,
        ];

        $smsUrl = $this->buildWebhookUrl('/api/webhooks/monthly-numbers/sms');
        $statusUrl = $this->buildWebhookUrl('/api/webhooks/monthly-numbers/status');

        if ($smsUrl !== null) {
            $payload['SmsUrl'] = $smsUrl;
        }

        if ($statusUrl !== null) {
            $payload['StatusCallback'] = $statusUrl;
        }

        $response = $this->request()->asForm()->post($this->resourcePath('IncomingPhoneNumbers.json'), $payload);

        try {
            $response->throw();
        } catch (RequestException $e) {
            $twilioCode = (int) ($response->json('code') ?? 0);

            // Twilio rejects non-https/non-public callback URLs (error 21402).
            if ($response->status() === 400 && $twilioCode === 21402 && (isset($payload['SmsUrl']) || isset($payload['StatusCallback']))) {
                Log::warning('Twilio rejected callback URL during number purchase; retrying without callbacks.', [
                    'phone_number' => $phoneNumberE164,
                    'sms_url' => $payload['SmsUrl'] ?? null,
                    'status_callback' => $payload['StatusCallback'] ?? null,
                    'twilio_message' => $response->json('message'),
                ]);

                $response = $this->request()->asForm()->post(
                    $this->resourcePath('IncomingPhoneNumbers.json'),
                    ['PhoneNumber' => $phoneNumberE164]
                );

                $response->throw();
            } else {
                throw $e;
            }
        }

        return (array) $response->json();
    }

    private function buildWebhookUrl(string $path): ?string
    {
        $baseUrl = trim((string) config('services.twilio.webhook_base_url', config('app.url', '')));

        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            return null;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return null;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function releaseIncomingNumber(string $incomingNumberSid): bool
    {
        $response = $this->request()->delete($this->resourcePath("IncomingPhoneNumbers/{$incomingNumberSid}.json"));

        return $response->successful() || $response->status() === 204 || $response->status() === 404;
    }

    public function sendSms(string $from, string $to, string $body): array
    {
        $payload = [
            'To' => $to,
            'Body' => $body,
        ];

        $messagingServiceSid = (string) config('services.twilio.messaging_service_sid', '');
        if ($messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $payload['From'] = $from;
        }

        $response = $this->request()->asForm()->post($this->resourcePath('Messages.json'), $payload);

        $response->throw();

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        $sid = (string) config('services.twilio.account_sid', '');
        $token = (string) config('services.twilio.auth_token', '');

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio credentials are not configured.');
        }

        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth($sid, $token)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.twilio.base_url', 'https://api.twilio.com'), '/');
        $version = trim((string) config('services.twilio.api_version', '2010-04-01'), '/');
        $sid = (string) config('services.twilio.account_sid', '');

        return "{$base}/{$version}/Accounts/{$sid}";
    }

    private function resourcePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}
