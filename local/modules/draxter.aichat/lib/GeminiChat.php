<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class GeminiChat
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param Product[] $catalogProducts
     */
    public static function streamNative(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        bool $detailed = false,
        array $catalogProducts = []
    ): void {
        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(500000 * (2 ** ($attempt - 1)));
            }
            try {
                self::streamNativeOnce($apiKey, $model, $systemPrompt, $messages, $detailed);

                return;
            } catch (\RuntimeException $e) {
                $lastError = $e;
                if ($attempt >= 2 || !self::isRetryableError($e)) {
                    throw $e;
                }
            }
        }
        throw $lastError ?? new \RuntimeException('Gemini stream error');
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function complete(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        int $maxTokens = 400
    ): string {
        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(500000 * (2 ** ($attempt - 1)));
            }
            try {
                return self::completeOnce($apiKey, $model, $systemPrompt, $messages, $maxTokens);
            } catch (\RuntimeException $e) {
                $lastError = $e;
                if ($attempt >= 2 || !self::isRetryableError($e)) {
                    throw $e;
                }
            }
        }
        throw $lastError ?? new \RuntimeException('Gemini complete error');
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function completeOnce(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        int $maxTokens = 400
    ): string {
        $useVertex = Config::geminiUseVertex();
        $base = $useVertex
            ? 'https://aiplatform.googleapis.com/v1/publishers/google/models/'
            : 'https://generativelanguage.googleapis.com/v1beta/models/';

        $url = $base . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => self::buildContents($messages),
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        $ch = curl_init($url);
        CurlHelper::applySslOptions($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => Json::encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlHelper::close($ch);

        if ($raw === false) {
            throw new \RuntimeException($error ?: 'Gemini complete error');
        }
        if ($status >= 400) {
            ApiErrorLog::write('gemini', 'chat.complete', $status, (string)$raw);
            throw new \RuntimeException("Gemini HTTP {$status}" . ($raw !== '' ? ': ' . trim($raw) : ''));
        }

        try {
            $json = Json::decode($raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Некорректный ответ Gemini');
        }

        $text = '';
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $chunk = $part['text'] ?? '';
            if ($chunk !== '') {
                $text .= $chunk;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Модель вернула пустой ответ');
        }

        return $text;
    }

    private static function isRetryableError(\RuntimeException $e): bool
    {
        return LlmClient::isQuotaOrRateLimitError($e)
            || (bool)preg_match('/\b503\b|unavailable|overloaded/i', $e->getMessage());
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function streamNativeOnce(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        bool $detailed = false
    ): void {
        $useVertex = Config::geminiUseVertex();
        $base = $useVertex
            ? 'https://aiplatform.googleapis.com/v1/publishers/google/models/'
            : 'https://generativelanguage.googleapis.com/v1beta/models/';

        $url = $base . rawurlencode($model) . ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => self::buildContents($messages),
            'generationConfig' => [
                'temperature' => 0.5,
                'maxOutputTokens' => ChatApi::outputTokenLimit($detailed),
            ],
        ];

        $lineBuffer = '';
        $wrote = false;
        $truncated = false;
        $headersSent = false;
        $ensureHeaders = static function () use (&$headersSent): void {
            if ($headersSent) {
                return;
            }
            $headersSent = true;
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache');
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        };

        $ch = curl_init($url);
        CurlHelper::applySslOptions($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => Json::encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$lineBuffer, &$wrote, &$truncated, $ensureHeaders) {
                $lineBuffer .= $chunk;
                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    $text = self::extractTextFromSseLine($line, $truncated);
                    if ($text !== '') {
                        $wrote = true;
                        $ensureHeaders();
                        echo $text;
                        if (function_exists('ob_flush')) {
                            @ob_flush();
                        }
                        flush();
                    }
                }

                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => 180,
        ]);

        $ok = curl_exec($ch);
        if ($ok === false) {
            $err = curl_error($ch);
            CurlHelper::close($ch);
            throw new \RuntimeException($err ?: 'Gemini stream error');
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlHelper::close($ch);

        if ($lineBuffer !== '') {
            $text = self::extractTextFromSseLine($lineBuffer, $truncated);
            if ($text !== '') {
                $wrote = true;
                $ensureHeaders();
                echo $text;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }

        if ($status >= 400) {
            ApiErrorLog::write('gemini', 'chat.stream', $status, trim($lineBuffer) ?: 'HTTP error');
            throw new \RuntimeException("Gemini HTTP {$status}" . ($lineBuffer !== '' ? ': ' . trim($lineBuffer) : ''));
        }

        if (!$wrote) {
            throw new \RuntimeException('Модель вернула пустой ответ. Попробуйте ещё раз.');
        }

        if ($truncated) {
            $ensureHeaders();
            echo "\n\n*(Ответ сокращён — напишите «подробнее», если нужно продолжение.)*";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function buildContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? '';
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content'] ?? '']],
            ];
        }

        if ($contents === []) {
            throw new \RuntimeException('No user message');
        }

        return $contents;
    }

    private static function extractTextFromSseLine(string $line, bool &$truncated): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        $data = $line;
        if (strpos($line, 'data:') === 0) {
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                return '';
            }
        } elseif ($line[0] !== '{') {
            return '';
        }

        try {
            $json = Json::decode($data);
        } catch (\Throwable $e) {
            return '';
        }

        if (($json['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
            $truncated = true;
        }

        $out = '';
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $text = $part['text'] ?? '';
            if ($text !== '') {
                $out .= $text;
            }
        }

        return $out;
    }
}
