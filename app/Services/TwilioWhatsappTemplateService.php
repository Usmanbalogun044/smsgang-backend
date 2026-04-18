<?php

namespace App\Services;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioWhatsappTemplateService
{
    public function syncTemplate(WhatsappTemplate $template): array
    {
        if (! $template->content_sid) {
            return ['synced' => false, 'reason' => 'missing_content_sid'];
        }

        $content = $this->request()->get($this->resourcePath('Content/' . $template->content_sid));
        $content->throw();

        $contentPayload = (array) $content->json();
        $approvalStatus = $this->fetchApprovalStatus($template->content_sid);

        $template->update([
            'provider_status' => (string) ($contentPayload['status'] ?? $approvalStatus['status'] ?? $template->provider_status ?? 'unknown'),
            'approval_status' => (string) ($approvalStatus['status'] ?? $contentPayload['status'] ?? $template->approval_status ?? 'unknown'),
            'approval_reason' => $approvalStatus['reason'] ?? $template->approval_reason,
            'provider_payload' => $contentPayload,
            'approved_at' => in_array(($approvalStatus['status'] ?? ''), ['approved'], true)
                ? ($template->approved_at ?? now())
                : $template->approved_at,
            'approval_requested_at' => $template->approval_requested_at ?? now(),
            'last_synced_at' => now(),
        ]);

        return [
            'synced' => true,
            'template' => $template->fresh(),
            'provider' => $contentPayload,
            'approval' => $approvalStatus,
        ];
    }

    public function listTemplates(): array
    {
        $response = $this->request()->get($this->resourcePath('Content'));
        $response->throw();

        return (array) ($response->json('content') ?? $response->json('contents') ?? $response->json('data') ?? []);
    }

    private function fetchApprovalStatus(string $contentSid): array
    {
        $response = $this->request()->get($this->resourcePath("Content/{$contentSid}/ApprovalRequests/whatsapp"));

        if (! $response->successful()) {
            return ['status' => null, 'reason' => null];
        }

        $payload = (array) $response->json();

        return [
            'status' => (string) ($payload['status'] ?? $payload['approval_status'] ?? $payload['state'] ?? 'unknown'),
            'reason' => $payload['reason'] ?? $payload['rejection_reason'] ?? $payload['message'] ?? null,
        ];
    }

    private function request(): PendingRequest
    {
        $sid = (string) config('services.twilio.account_sid', '');
        $token = (string) config('services.twilio.auth_token', '');

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio credentials are not configured.');
        }

        return Http::baseUrl('https://content.twilio.com/v1')
            ->withBasicAuth($sid, $token)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson();
    }

    private function resourcePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}
