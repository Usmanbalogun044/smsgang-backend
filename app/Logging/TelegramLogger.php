<?php

namespace App\Logging;

use Monolog\Logger;

class TelegramLogger
{
    public function __invoke(array $config): Logger
    {
        return new Logger('telegram', [
            new TelegramLoggerHandler(
                $config['token'],
                $config['chat_id'],
                $config['level'] ?? \Monolog\Level::Debug,
            ),
        ]);
    }
}
