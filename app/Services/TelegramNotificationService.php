<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    private string $botToken;
    private string $chatId;
    private string $channelChatId;
    private bool $enabled;
    private string $baseUrl = 'https://api.telegram.org/bot';

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
        $this->channelChatId = config('services.telegram.channel_chat_id', $this->chatId);
        $this->enabled = config('services.telegram.enabled', false);
    }

    public function sendChannelPost(string $message, ?string $parseMode = 'Markdown'): bool
    {
        if (! $this->enabled || ! $this->botToken || ! $this->channelChatId) {
            return false;
        }

        return $this->sendMessage($message, $this->channelChatId, $parseMode);
    }

    /**
     * Send order notification to Telegram
     */
    public function sendOrderNotification($order, $user): bool
    {
        if (!$this->enabled || !$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $message = $this->formatOrderMessage($order, $user);
            return $this->sendMessage($message);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram order notification', [
                'error' => $e->getMessage(),
                'order_id' => $order->id ?? null,
            ]);
            return false;
        }
    }

    /**
     * Send SMM order notification
     */
    public function sendSmmOrderNotification($order, $user, $service): bool
    {
        if (!$this->enabled || !$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $message = "🎯 *NEW SMM ORDER*\n\n";
            $message .= "👤 *User:* " . $user->name . " (@" . $user->phone . ")\n";
            $message .= "📱 *Service:* " . $service->name . "\n";
            $message .= "📊 *Quantity:* " . number_format($order->quantity) . "\n";
            $message .= "💰 *Cost:* ₦" . number_format($order->total_cost_ngn, 2) . "\n";
            $message .= "🔗 *Link:* " . substr($order->link, 0, 50) . "...\n";
            $message .= "📌 *Order ID:* `" . $order->id . "`\n";
            $message .= "⏰ *Time:* " . $order->created_at->format('Y-m-d H:i:s') . "\n";
            $message .= "🔑 *Provider Order:* " . ($order->crestpanel_order_id ?? 'Pending');

            return $this->sendMessage($message);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram SMM notification', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send wallet transaction notification
     */
    public function sendTransactionNotification($user, $amount, $type, $description): bool
    {
        if (!$this->enabled || !$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $icon = $type === 'debit' ? '💸' : '💰';
            $action = $type === 'debit' ? 'DEDUCTED' : 'CREDITED';

            $message = "*{$icon} WALLET TRANSACTION*\n\n";
            $message .= "👤 *User:* " . $user->name . "\n";
            $message .= "💰 *Amount:* ₦" . number_format($amount, 2) . " ({$action})\n";
            $message .= "📝 *Description:* " . $description . "\n";
            $message .= "💳 *New Balance:* ₦" . number_format($user->wallet?->balance ?? 0, 2) . "\n";
            $message .= "⏰ *Time:* " . now()->format('Y-m-d H:i:s');

            return $this->sendMessage($message);
        } catch (\Exception $e) {
            Log::error('Failed to send transaction notification', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send referral notification
     */
    public function sendReferralNotification($referrer, $referred, $amount): bool
    {
        if (!$this->enabled || !$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $message = "🎁 *NEW REFERRAL REWARD*\n\n";
            $message .= "👤 *Referrer:* " . $referrer->name . "\n";
            $message .= "🆕 *New User:* " . $referred->name . "\n";
            $message .= "💰 *Reward:* ₦" . number_format($amount, 2) . "\n";
            $message .= "✅ *Status:* Credited to wallet\n";
            $message .= "⏰ *Time:* " . now()->format('Y-m-d H:i:s');

            return $this->sendMessage($message);
        } catch (\Exception $e) {
            Log::error('Failed to send referral notification', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send alert/error notification
     */
    public function sendAlert(string $title, string $message, array $details = []): bool
    {
        if (!$this->enabled || !$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $text = "⚠️ *{$title}*\n\n";
            $text .= $message . "\n\n";

            foreach ($details as $key => $value) {
                $text .= "*{$key}:* " . json_encode($value) . "\n";
            }

            $text .= "\n⏰ *Time:* " . now()->format('Y-m-d H:i:s');

            return $this->sendMessage($text);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram alert', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format order message
     */
    private function formatOrderMessage($order, $user): string
    {
        $message = "📦 *NEW VIRTUAL NUMBER ORDER*\n\n";
        $message .= "👤 *User:* " . $user->name . "\n";
        $message .= "📱 *Country:* " . $order->country->name . "\n";
        $message .= "📞 *Number:* " . $order->virtual_number . "\n";
        $message .= "💰 *Amount:* ₦" . number_format($order->total_cost_ngn, 2) . "\n";
        $message .= "⏱️ *Validity:* " . $order->validity_minutes . " mins\n";
        $message .= "📌 *Order ID:* `" . $order->id . "`\n";
        $message .= "⏰ *Time:* " . $order->created_at->format('Y-m-d H:i:s');

        return $message;
    }

    /**
     * Send message to Telegram
     */
    private function sendMessage(string $message, ?string $targetChatId = null, ?string $parseMode = 'Markdown'): bool
    {
        try {
            $payload = [
                'chat_id' => $targetChatId ?: $this->chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ];

            if ($parseMode) {
                $payload['parse_mode'] = $parseMode;
            }

            $response = Http::post("{$this->baseUrl}{$this->botToken}/sendMessage", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Telegram API returned error', [
                'response' => $response->json(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Telegram API connection failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
