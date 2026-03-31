<?php

namespace App\Logging\Formatters;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class DiscordFormatter extends JsonFormatter
{
    /**
     * Format a log record as a Discord webhook payload (embed).
     */
    public function format(LogRecord $record): string
    {
        $color = $this->getColorForLevel($record->level->value);
        $title = ucfirst($record->level->name) . ' - ' . config('app.name', 'SMS Gang');

        $description = $record->message;
        $context = $record->context ?? [];
        $extra = $record->extra ?? [];

        // Build the embed payload for Discord
        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => $record->datetime->toIso8601String(),
            'footer' => [
                'text' => 'SMS Gang Production Logs',
                'icon_url' => null,
            ],
            'fields' => [],
        ];

        // Add context as fields
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = (string) $value;
            if (strlen($value) > 1024) {
                $value = substr($value, 0, 1021) . '...';
            }

            $embed['fields'][] = [
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'value' => "```\n{$value}\n```",
                'inline' => false,
            ];
        }

        // Add environment info
        $embed['fields'][] = [
            'name' => 'Environment',
            'value' => config('app.env'),
            'inline' => true,
        ];

        $embed['fields'][] = [
            'name' => 'Application',
            'value' => config('app.name', 'SMS Gang'),
            'inline' => true,
        ];

        // Create webhook payload
        $payload = [
            'embeds' => [$embed],
            'username' => config('app.name', 'SMS Gang') . ' Logger',
            'avatar_url' => 'https://discord.com/api/oauth2/authorize',
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get Discord embed color based on log level
     */
    private function getColorForLevel(int $level): int
    {
        return match($level) {
            100 => 0x808080, // DEBUG - Gray
            200 => 0x0099FF, // INFO - Blue
            250 => 0x00FF00, // NOTICE - Green
            300 => 0xFFFF00, // WARNING - Yellow
            400 => 0xFFA500, // ERROR - Orange
            500 => 0xFF0000, // CRITICAL - Red
            550 => 0xFF0000, // ALERT - Red
            600 => 0xFF0000, // EMERGENCY - Red
            default => 0x808080,
        };
    }
}
