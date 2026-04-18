<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function handleInbound(Request $request): JsonResponse
    {
        $payload = $request->all();
        $messageSid = (string) ($payload['MessageSid'] ?? '');

        if ($messageSid === '') {
            return response()->json(['message' => 'MessageSid is required.'], 400);
        }

        $message = WhatsappMessage::updateOrCreate(
            ['message_sid' => $messageSid],
            [
                'direction' => 'inbound',
                'status' => (string) ($payload['SmsStatus'] ?? $payload['MessageStatus'] ?? 'received'),
                'from_number' => $payload['From'] ?? null,
                'to_number' => $payload['To'] ?? null,
                'provider_payload' => $payload,
            ]
        );

        Log::channel('activity')->info('WhatsApp inbound callback received', [
            'message_sid' => $messageSid,
            'whatsapp_message_id' => $message->id,
        ]);

        return response()->json(['message' => 'ok']);
    }

    public function handleStatus(Request $request): JsonResponse
    {
        $payload = $request->all();
        $messageSid = (string) ($payload['MessageSid'] ?? '');

        if ($messageSid === '') {
            return response()->json(['message' => 'MessageSid is required.'], 400);
        }

        $status = (string) ($payload['MessageStatus'] ?? 'queued');
        $providerCost = isset($payload['Price']) ? abs((float) $payload['Price']) : null;
        $providerCurrency = isset($payload['PriceUnit']) ? strtolower((string) $payload['PriceUnit']) : null;
        $fxRate = (float) Setting::get('exchange_rate_usd_ngn', 1600);

        $providerCostNgn = null;
        if ($providerCost !== null) {
            $providerCostNgn = $providerCurrency === 'usd'
                ? round($providerCost * $fxRate, 2)
                : round($providerCost, 2);
        }

        $message = WhatsappMessage::query()->where('message_sid', $messageSid)->first();

        if (! $message) {
            $message = WhatsappMessage::create([
                'message_sid' => $messageSid,
                'direction' => 'outbound',
                'status' => $status,
                'from_number' => $payload['From'] ?? null,
                'to_number' => $payload['To'] ?? null,
                'provider_payload' => $payload,
            ]);
        }

        $charged = (float) $message->charged_amount_ngn;
        $profit = $providerCostNgn !== null
            ? round(max(0, $charged - $providerCostNgn), 2)
            : (float) $message->profit_amount_ngn;

        $message->update([
            'status' => $status,
            'provider_cost_value' => $providerCost ?? $message->provider_cost_value,
            'provider_cost_currency' => $providerCurrency ?? $message->provider_cost_currency,
            'provider_cost_ngn_estimate' => $providerCostNgn ?? $message->provider_cost_ngn_estimate,
            'fx_rate_used' => $providerCostNgn !== null ? $fxRate : $message->fx_rate_used,
            'profit_amount_ngn' => $profit,
            'error_code' => isset($payload['ErrorCode']) ? (string) $payload['ErrorCode'] : $message->error_code,
            'error_message' => isset($payload['ErrorMessage']) ? (string) $payload['ErrorMessage'] : $message->error_message,
            'provider_payload' => $payload,
            'delivered_at' => in_array($status, ['delivered', 'read'], true)
                ? ($message->delivered_at ?? now())
                : $message->delivered_at,
            'read_at' => $status === 'read'
                ? ($message->read_at ?? now())
                : $message->read_at,
            'failed_at' => in_array($status, ['failed', 'undelivered'], true)
                ? ($message->failed_at ?? now())
                : $message->failed_at,
        ]);

        Log::channel('activity')->info('WhatsApp status callback processed', [
            'message_sid' => $messageSid,
            'status' => $status,
        ]);

        return response()->json(['message' => 'ok']);
    }
}
