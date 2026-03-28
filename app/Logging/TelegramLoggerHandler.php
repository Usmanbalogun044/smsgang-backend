<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class TelegramLoggerHandler extends AbstractProcessingHandler
{
    private string $token;

    private string $chatId;

    public function __construct(string $token, string $chatId, Level|string|int $level = Level::Debug, bool $bubble = true)
    {
        $this->token = $token;
        $this->chatId = $chatId;

        if (is_string($level)) {
            $level = Level::fromName($level);
        }

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (empty($this->token) || empty($this->chatId)) {
            return;
        }

        $text = "<b>[SMS Gang]</b> <b>{$record->level->name}</b>\n"
            . "<code>" . $this->escape($record->message) . "</code>";

        if (! empty($record->context)) {
            $context = json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $text .= "\n<pre>" . $this->escape($context) . "</pre>";
        }

        // Telegram has a 4096 char limit for messages
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3997) . '...';
        }

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode([
                    'chat_id' => $this->chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]),
                'timeout' => 5,
            ],
        ]));
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
