<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Services\TwilioWhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappController extends Controller
{

    public function stats(): JsonResponse
    {
        $messages = WhatsappMessage::query();

        $totalMessages = (clone $messages)->count();
        $todayMessages = (clone $messages)->whereDate('created_at', today())->count();
        $successful = (clone $messages)->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $failed = (clone $messages)->whereIn('status', ['failed', 'undelivered'])->count();

        $revenue = (float) (clone $messages)->sum('charged_amount_ngn');
        $providerCost = (float) (clone $messages)->sum('provider_cost_ngn_estimate');
        $profit = (float) (clone $messages)->sum('profit_amount_ngn');

        return response()->json([
            'total_messages' => $totalMessages,
            'messages_today' => $todayMessages,
            'successful_messages' => $successful,
            'failed_messages' => $failed,
            'total_revenue_ngn' => round($revenue, 2),
            'total_provider_cost_ngn' => round($providerCost, 2),
            'total_profit_ngn' => round($profit, 2),
            'gross_margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
        ]);
    }

    public function templates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WhatsappTemplate::query()
            ->latest();
        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 30)));
    }

    public function syncTemplates(): JsonResponse
    {
        $remoteTemplates = $this->templateService->listTemplates();
        $synced = [];

        foreach ($remoteTemplates as $remoteTemplate) {
            if (! is_array($remoteTemplate)) {
                continue;
            }

            $contentSid = (string) ($remoteTemplate['sid'] ?? $remoteTemplate['content_sid'] ?? '');
            $name = (string) ($remoteTemplate['friendly_name'] ?? $remoteTemplate['name'] ?? $contentSid);

            if ($contentSid === '' && $name === '') {
                continue;
            }

            $approvalStatus = (string) ($remoteTemplate['approval_status'] ?? $remoteTemplate['status'] ?? 'unknown');
            $bodyPreview = $this->extractBodyPreview($remoteTemplate);
            $category = $this->extractCategory($remoteTemplate);

            $template = WhatsappTemplate::query()->updateOrCreate(
                ['content_sid' => $contentSid ?: null, 'name' => $name],
                [
                    'name' => $name,
                    'category' => $category,
                    'body_preview' => $bodyPreview,
                    'variables_schema' => $remoteTemplate['variables'] ?? null,
                    'provider_status' => (string) ($remoteTemplate['status'] ?? $approvalStatus),
                    'approval_status' => $approvalStatus,
                    'approval_reason' => $remoteTemplate['rejection_reason'] ?? $remoteTemplate['reason'] ?? null,
                    'provider_payload' => $remoteTemplate,
                    'last_synced_at' => now(),
                    'is_active' => ! in_array($approvalStatus, ['rejected', 'disabled', 'paused'], true),
                ]
            );

            $synced[] = [
                'synced' => true,
                'template_id' => $template->id,
                'content_sid' => $template->content_sid,
                'approval_status' => $template->approval_status,
                'provider_status' => $template->provider_status,
            ];
        }

        return response()->json([
            'message' => 'Twilio template sync completed.',
            'results' => $synced,
        ]);
    }

    private function extractBodyPreview(array $template): ?string
    {
        foreach (['body_preview', 'body', 'text', 'content'] as $key) {
            $value = $template[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $types = $template['types'] ?? null;
        if (is_array($types)) {
            foreach ($types as $type) {
                if (! is_array($type)) {
                    continue;
                }

                foreach (['twilio/text', 'twilio/whatsapp'] as $textType) {
                    if (! isset($type[$textType]) || ! is_array($type[$textType])) {
                        continue;
                    }

                    $body = $type[$textType]['body'] ?? null;
                    if (is_string($body) && trim($body) !== '') {
                        return $body;
                    }
                }
            }
        }

        return null;
    }

    private function extractCategory(array $template): string
    {
        $category = strtolower((string) ($template['category'] ?? $template['whatsapp_category'] ?? 'utility'));

        return in_array($category, ['authentication', 'utility', 'marketing', 'system'], true)
            ? $category
            : 'utility';
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:whatsapp_templates,name'],
            'category' => ['required', 'string', 'in:authentication,utility,marketing,system'],
            'content_sid' => ['required', 'string', 'max:64', 'unique:whatsapp_templates,content_sid'],
            'body_preview' => ['nullable', 'string', 'max:5000'],
            'variables_schema' => ['nullable', 'array'],
            'unit_price_ngn' => ['nullable', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template = WhatsappTemplate::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Template created successfully.',
            'template' => $template,
        ], 201);
    }

    public function updateTemplate(Request $request, WhatsappTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', 'unique:whatsapp_templates,name,' . $template->id],
            'category' => ['sometimes', 'string', 'in:authentication,utility,marketing,system'],
            'content_sid' => ['sometimes', 'string', 'max:64', 'unique:whatsapp_templates,content_sid,' . $template->id],
            'body_preview' => ['nullable', 'string', 'max:5000'],
            'variables_schema' => ['nullable', 'array'],
            'unit_price_ngn' => ['nullable', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Template updated successfully.',
            'template' => $template->fresh(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'whatsapp_mode' => 'production',
            'whatsapp_unit_price_ngn' => (float) Setting::get('whatsapp_unit_price_ngn', 20),
            'whatsapp_production_from' => (string) Setting::get('whatsapp_production_from', ''),
            'whatsapp_messaging_service_sid' => (string) Setting::get('whatsapp_messaging_service_sid', ''),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_unit_price_ngn' => ['sometimes', 'numeric', 'min:0.01'],
            'whatsapp_production_from' => ['sometimes', 'nullable', 'string', 'max:40'],
            'whatsapp_messaging_service_sid' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        foreach ($validated as $key => $value) {
            if (in_array($key, ['whatsapp_production_from', 'whatsapp_messaging_service_sid'], true)) {
                Setting::set($key, $value ?? '');
                continue;
            }

            Setting::set($key, $value);
        }

        Setting::set('whatsapp_mode', 'production');

        return response()->json([
            'message' => 'WhatsApp settings saved.',
            'settings' => [
                'whatsapp_mode' => 'production',
                'whatsapp_unit_price_ngn' => (float) Setting::get('whatsapp_unit_price_ngn', 20),
                'whatsapp_production_from' => (string) Setting::get('whatsapp_production_from', ''),
                'whatsapp_messaging_service_sid' => (string) Setting::get('whatsapp_messaging_service_sid', ''),
            ],
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WhatsappMessage::query()->with(['user:id,name,email', 'template:id,name'])->latest();

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('message_sid', 'like', '%' . $search . '%')
                    ->orWhere('to_number', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 40)));
    }
}
