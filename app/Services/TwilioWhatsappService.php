<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioWhatsappService
{
    public function sendTemplate(string $to, string $contentSid, array $variables = []): array
    {
        $payload = [
            'To' => $this->normalizeWhatsappAddress($to),
            'ContentSid' => $contentSid,
        ];

        if (! empty($variables)) {
            $payload['ContentVariables'] = json_encode($variables, JSON_UNESCAPED_SLASHES);
        }

        $messagingServiceSid = trim((string) Setting::get('whatsapp_messaging_service_sid', (string) config('services.twilio.whatsapp_messaging_service_sid', '')));
        if ($messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $payload['From'] = $this->resolveFromAddress();
        }

        $statusCallback = $this->buildWebhookUrl('/api/webhooks/whatsapp/status');
        if ($statusCallback !== null) {
            $payload['StatusCallback'] = $statusCallback;
        }

        $response = $this->request()->asForm()->post($this->resourcePath('Messages.json'), $payload);
        $response->throw();

        return (array) $response->json();
    }

    private function resolveFromAddress(): string
    {
        $from = trim((string) Setting::get('whatsapp_production_from', (string) config('services.twilio.whatsapp_production_from', '')));
        if ($from === '') {
            throw new RuntimeException('WhatsApp sender is not configured.');
        }

        return $this->normalizeWhatsappAddress($from);
    }

    private function normalizeWhatsappAddress(string $value): string
    {
        $trimmed = trim($value);
        if (str_starts_with(strtolower($trimmed), 'whatsapp:')) {
            return 'whatsapp:' . substr($trimmed, 9);
        }

        return 'whatsapp:' . $trimmed;
    }

    private function buildWebhookUrl(string $path): ?string
    {
        $baseUrl = trim((string) config('services.twilio.webhook_base_url', config('app.url', '')));
        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
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
