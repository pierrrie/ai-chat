<?php

namespace Draxter\Aichat;

class ChatApi
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    public static function trimMessagesForApi(array $messages, int $max = 8): array
    {
        if (count($messages) <= $max) {
            return $messages;
        }
        return array_slice($messages, -$max);
    }

    public static function outputTokenLimit(bool $detailed): int
    {
        return $detailed ? 2048 : 1536;
    }

    /** Отправляет заголовки потока сразу, чтобы прокси (nginx) не отдал 504 до первого токена LLM. */
    public static function beginStream(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        echo ' ';
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
