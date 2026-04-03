<?php

namespace App\Http\Controllers\Api;

use App\Enums\TwilioMessageDirection;
use App\Http\Controllers\Controller;
use App\Models\TwilioMessage;
use App\Models\TwilioNumberSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
	public function handleSms(Request $request): JsonResponse
	{
		$payload = $request->all();
		$messageSid = (string) ($payload['MessageSid'] ?? '');

		if ($messageSid === '') {
			return response()->json(['message' => 'MessageSid is required.'], 400);
		}

		$toNumber = (string) ($payload['To'] ?? '');

		$subscription = TwilioNumberSubscription::query()
			->where('phone_number_e164', $toNumber)
			->first();

		TwilioMessage::updateOrCreate(
			['message_sid' => $messageSid],
			[
				'user_id' => $subscription?->user_id,
				'twilio_number_subscription_id' => $subscription?->id,
				'direction' => TwilioMessageDirection::Inbound,
				'status' => $payload['SmsStatus'] ?? 'received',
				'from_number' => $payload['From'] ?? null,
				'to_number' => $toNumber ?: null,
				'body' => $payload['Body'] ?? null,
				'segments' => (int) ($payload['NumSegments'] ?? 1),
				'received_at' => now(),
				'provider_payload' => $payload,
			]
		);

		Log::channel('activity')->info('Twilio inbound SMS received', [
			'message_sid' => $messageSid,
			'subscription_id' => $subscription?->id,
			'to' => $toNumber,
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

		$message = TwilioMessage::query()->where('message_sid', $messageSid)->first();

		if (! $message) {
			// Persist unknown status callback to avoid data loss when callbacks arrive before send-flow persistence.
			$message = TwilioMessage::create([
				'message_sid' => $messageSid,
				'direction' => TwilioMessageDirection::Outbound,
				'status' => $payload['MessageStatus'] ?? null,
				'from_number' => $payload['From'] ?? null,
				'to_number' => $payload['To'] ?? null,
				'provider_payload' => $payload,
			]);
		}

		$message->update([
			'status' => $payload['MessageStatus'] ?? $message->status,
			'provider_cost_usd' => isset($payload['Price']) ? abs((float) $payload['Price']) : $message->provider_cost_usd,
			'currency' => $payload['PriceUnit'] ?? $message->currency,
			'provider_payload' => $payload,
			'sent_at' => $message->sent_at ?? now(),
			'delivered_at' => in_array(($payload['MessageStatus'] ?? ''), ['delivered', 'read'], true)
				? now()
				: $message->delivered_at,
		]);

		Log::channel('activity')->info('Twilio message status callback processed', [
			'message_sid' => $messageSid,
			'status' => $message->status,
		]);

		return response()->json(['message' => 'ok']);
	}
}
