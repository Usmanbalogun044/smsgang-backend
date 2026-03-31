<?php

namespace App\Logging\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log as LaravelLog;

class DiscordHandler extends AbstractProcessingHandler
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl, $level = Level::Info)
    {
        // Convert string level to Monolog Level if needed
        if (is_string($level)) {
            $level = Level::fromName(strtoupper($level));
        }
        
        parent::__construct($level);
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Send log record to Discord webhook
     */
    protected function write(LogRecord $record): void
    {
        try {
            if (empty($this->webhookUrl)) {
                error_log('Discord webhook URL is empty');
                return;
            }

            $payload = $this->formatForDiscord($record);

            Http::timeout(15)
                ->connectTimeout(10)
                ->retry(2, 500)
                ->post($this->webhookUrl, $payload);
        } catch (\Exception $e) {
            error_log('Discord logging failed: ' . $e->getMessage() . ' | Webhook: ' . substr($this->webhookUrl, 0, 50));
        }
    }

    /**
     * Format the log record as a Discord embed
     */
    private function formatForDiscord(LogRecord $record): array
    {
        $color = $this->getColorForLevel($record->level->value);
        $title = ucfirst($record->level->name) . ' - ' . config('app.name', 'SMS Gang');

        $fields = [];

        // Add message
        if (!empty($record->message)) {
            $msg = $record->message;
            if (strlen($msg) > 1024) {
                $msg = substr($msg, 0, 1021) . '...';
            }
            $fields[] = [
                'name' => 'Message',
                'value' => "```\n{$msg}\n```",
                'inline' => false,
            ];
        }

        // Add context data
        $context = $record->context ?? [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            $value = (string) $value;
            if (strlen($value) > 1024) {
                $value = substr($value, 0, 1021) . '...';
            }

            // Skip very long or sensitive values
            if (strpos($key, 'password') !== false || strpos($key, 'secret') !== false) {
                $value = '***REDACTED***';
            }

            $fields[] = [
                'name' => ucfirst(str_replace(['_', '-'], ' ', $key)),
                'value' => "```\n{$value}\n```",
                'inline' => false,
            ];
        }

        // Add environment info
        $fields[] = [
            'name' => 'Environment',
            'value' => config('app.env'),
            'inline' => true,
        ];

        $fields[] = [
            'name' => 'Application',
            'value' => config('app.name', 'SMS Gang'),
            'inline' => true,
        ];

        // Create embed
        $embed = [
            'title' => $title,
            'description' => !empty($record->message) ? $record->message : 'Log entry',
            'color' => $color,
            'timestamp' => $record->datetime->format('c'),
            'footer' => [
                'text' => 'SMS Gang Monitoring',
            ],
            'fields' => $fields,
        ];

        return [
            'embeds' => [$embed],
            'username' => 'SMS Gang Logger',
            'avatar_url' => 'https://cdn.discordapp.com/embed/avatars/0.png',
        ];
    }

    /**
     * Get Discord embed color based on log level (decimal to hex)
     */
    private function getColorForLevel(int $level): int
    {
        return match($level) {
            100 => 8421504,   // DEBUG - Gray (#808080)
            200 => 39423,    // INFO - Blue (#009AFF)
            250 => 65280,    // NOTICE - Green (#00FF00)
            300 => 16776960, // WARNING - Yellow (#FFFF00)
            400 => 16744448, // ERROR - Orange (#FFA500)
            500, 550, 600 => 16711680, // CRITICAL/ALERT/EMERGENCY - Red (#FF0000)
            default => 8421504,
        };
    }
}
