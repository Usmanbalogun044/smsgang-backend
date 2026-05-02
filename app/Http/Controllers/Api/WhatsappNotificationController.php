<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Services\TwilioWhatsappService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WhatsappNotificationController extends Controller
{

    public function templates(): JsonResponse
    {
        $globalUnitPrice = (float) Setting::get('whatsapp_unit_price_ngn', 20);

        $query = WhatsappTemplate::query()
            ->where('is_active', true)
            ->orderBy('name');

        if ($this->hasTemplateColumn('approval_status')) {
            $query->where('approval_status', 'approved');
        }

        $templates = $query->get()
            ->map(function (WhatsappTemplate $template) use ($globalUnitPrice) {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'category' => $template->category,
                    'body_preview' => $template->body_preview,
                    'variables_schema' => $template->variables_schema ?? [],
                    'unit_price_ngn' => $globalUnitPrice,
                ];
            })
            ->values();

        return response()->json([
            'data' => $templates,
            'global_unit_price_ngn' => $globalUnitPrice,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'to' => ['required', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $template = WhatsappTemplate::query()
            ->where('is_active', true)
            ->findOrFail((int) $validated['template_id']);

        if ($this->hasTemplateColumn('approval_status') && strtolower((string) $template->approval_status) !== 'approved') {
            return response()->json([
                'message' => 'Template is not approved for production sending.',
                'error' => 'template_not_approved',
            ], 422);
        }

        $unitPrice = (float) Setting::get('whatsapp_unit_price_ngn', 20);
        if ($unitPrice <= 0) {
            return response()->json([
                'message' => 'WhatsApp unit price is not configured correctly.',
                'error' => 'pricing_invalid',
            ], 422);
        }

        if (! $template->content_sid) {
            return response()->json([
                'message' => 'Selected template is not ready for dispatch.',
                'error' => 'template_not_sendable',
            ], 422);
        }

        $variables = (array) ($validated['variables'] ?? []);
        $normalizedTo = (string) $validated['to'];
        $billingReference = 'WHATSAPP_MSG_' . uniqid();

        $lock = Cache::lock('lock:whatsapp-send:' . sha1($user->id . '|' . $normalizedTo), 8);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'A WhatsApp send is already in progress for this recipient. Please retry shortly.',
                'error' => 'send_in_progress',
            ], 429);
        }

        try {
            $balanceBefore = (float) app(WalletService::class)->getBalance($user);

            $debit = app(WalletService::class)->deductFunds(
                $user,
                $unitPrice,
                $billingReference,
                'WhatsApp notification send to ' . $normalizedTo,
                'whatsapp_send'
            );

            if (! $debit) {
                return response()->json([
                    'message' => 'Insufficient wallet balance.',
                    'error' => 'insufficient_balance',
                ], 422);
            }

            try {
                $providerResponse = app(TwilioWhatsappService::class)->sendTemplate(
                    $normalizedTo,
                    (string) $template->content_sid,
                    $variables,
                );
            } catch (Throwable $e) {
                app(WalletService::class)->refundFunds(
                    $user,
                    $unitPrice,
                    'WHATSAPP_REFUND_' . $billingReference,
                    'Refund for failed WhatsApp dispatch',
                    'whatsapp_refund'
                );

                throw $e;
            }

            $providerCost = isset($providerResponse['price']) ? abs((float) $providerResponse['price']) : null;
            $providerCurrency = isset($providerResponse['price_unit']) ? strtolower((string) $providerResponse['price_unit']) : null;
            $fxRate = (float) Setting::get('exchange_rate_usd_ngn', 1600);

            $providerCostNgn = null;
            if ($providerCost !== null) {
                $providerCostNgn = $providerCurrency === 'usd'
                    ? round($providerCost * $fxRate, 2)
                    : round($providerCost, 2);
            }

            $charged = round($unitPrice, 2);
            $profit = $providerCostNgn !== null
                ? round(max(0, $charged - $providerCostNgn), 2)
                : round($charged, 2);

            $message = WhatsappMessage::create([
                'user_id' => $user->id,
                'whatsapp_template_id' => $template->id,
                'message_sid' => (string) ($providerResponse['sid'] ?? ''),
                'direction' => 'outbound',
                'status' => (string) ($providerResponse['status'] ?? 'queued'),
                'from_number' => $providerResponse['from'] ?? null,
                'to_number' => $providerResponse['to'] ?? ('whatsapp:' . ltrim($normalizedTo, '+')),
                'template_variables' => $variables,
                'unit_price_ngn' => $unitPrice,
                'quantity' => 1,
                'charged_amount_ngn' => $charged,
                'provider_cost_value' => $providerCost,
                'provider_cost_currency' => $providerCurrency,
                'provider_cost_ngn_estimate' => $providerCostNgn,
                'fx_rate_used' => $fxRate,
                'profit_amount_ngn' => $profit,
                'billing_status' => 'charged',
                'billing_reference' => $billingReference,
                'provider_payload' => $providerResponse,
                'sent_at' => now(),
            ]);

            $balanceAfter = (float) app(WalletService::class)->getBalance($user);

            return response()->json([
                'message' => 'WhatsApp notification queued successfully.',
                'data' => $this->serializeUserMessage($message, $balanceBefore, $balanceAfter),
            ], 201);
        } catch (Throwable $e) {
            Log::channel('activity')->error('WhatsApp send failed', [
                'user_id' => $user->id,
                'template_id' => $template->id,
                'to' => $normalizedTo,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'WhatsApp send failed. Please try again.',
                'error' => 'whatsapp_send_failed',
            ], 503);
        } finally {
            optional($lock)->release();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $messages = WhatsappMessage::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(30);

        return response()->json([
            'data' => $messages->getCollection()
                ->map(fn (WhatsappMessage $message) => $this->serializeUserMessage($message))
                ->values(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    private function serializeUserMessage(WhatsappMessage $message, ?float $balanceBefore = null, ?float $balanceAfter = null): array
    {
        return [
            'id' => $message->id,
            'template_id' => $message->whatsapp_template_id,
            'message_sid' => $message->message_sid,
            'status' => $message->status,
            'to' => $message->to_number,
            'unit_price_ngn' => (float) $message->unit_price_ngn,
            'quantity' => (int) $message->quantity,
            'charged_amount_ngn' => (float) $message->charged_amount_ngn,
            'billing_status' => $message->billing_status,
            'wallet_balance_before' => $balanceBefore,
            'wallet_balance_after' => $balanceAfter,
            'created_at' => optional($message->created_at)?->toISOString(),
        ];
    }

    private function hasTemplateColumn(string $column): bool
    {
        static $cache = [];

        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $cache[$column] = Schema::hasColumn('whatsapp_templates', $column);

        return $cache[$column];
    }
}
