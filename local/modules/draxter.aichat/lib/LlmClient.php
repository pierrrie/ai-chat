<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

class LlmClient
{
    /** @return array{url: string, apiKey: string, model: string, provider: string, auth?: string, extraHeaders?: array<string, string>} */
    public static function create(): array
    {
        $provider = Config::aiProvider();

        if ($provider === 'gemini') {
            $apiKey = Config::get('GEMINI_API_KEY');
            if ($apiKey === '') {
                throw new \RuntimeException('NO_API_KEY');
            }
            return [
                'provider' => 'gemini',
                'apiKey' => $apiKey,
                'model' => Config::get('GEMINI_MODEL', 'gemini-2.0-flash'),
                'url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'auth' => 'bearer',
                'extraHeaders' => [],
            ];
        }

        if ($provider === 'deepseek') {
            $apiKey = Config::get('DEEPSEEK_API_KEY');
            if ($apiKey === '') {
                throw new \RuntimeException('NO_API_KEY');
            }
            return [
                'provider' => 'deepseek',
                'apiKey' => $apiKey,
                'model' => Config::get('DEEPSEEK_MODEL', 'deepseek-chat'),
                'url' => 'https://api.deepseek.com/chat/completions',
                'auth' => 'bearer',
                'extraHeaders' => [],
            ];
        }

        if ($provider === 'perplexity') {
            $apiKey = Config::get('PERPLEXITY_API_KEY');
            if ($apiKey === '') {
                throw new \RuntimeException('NO_API_KEY');
            }
            return [
                'provider' => 'perplexity',
                'apiKey' => $apiKey,
                'model' => Config::get('PERPLEXITY_MODEL', 'sonar'),
                'url' => 'https://api.perplexity.ai/chat/completions',
                'auth' => 'bearer',
                'extraHeaders' => [],
            ];
        }

        $custom = CustomProviders::getById($provider);
        if ($custom !== null) {
            if ($custom['api_key'] === '') {
                throw new \RuntimeException('NO_API_KEY');
            }
            return [
                'provider' => $custom['id'],
                'apiKey' => $custom['api_key'],
                'model' => $custom['model'],
                'url' => $custom['url'],
                'auth' => $custom['auth'],
                'extraHeaders' => $custom['extra_headers'],
            ];
        }

        throw new \RuntimeException('NO_API_KEY');
    }

    /**
     * @param array{apiKey?: string, auth?: string, extraHeaders?: array<string, string>} $llm
     * @return string[]
     */
    private static function buildRequestHeaders(array $llm): array
    {
        $headers = ['Content-Type: application/json'];
        $auth = strtolower(trim((string)($llm['auth'] ?? 'bearer')));
        $apiKey = trim((string)($llm['apiKey'] ?? ''));

        if ($auth === 'bearer' && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        } elseif ($auth === 'api-key' && $apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        foreach ($llm['extraHeaders'] ?? [] as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $headers[] = $name . ': ' . trim((string)$value);
        }

        return $headers;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function streamOpenAiCompatible(
        array $llm,
        string $systemPrompt,
        array $messages,
        int $maxTokens = 2800
    ): void {
        $payload = [
            'model' => $llm['model'],
            'stream' => true,
            'temperature' => 0.5,
            'max_tokens' => $maxTokens,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                array_map(static fn($m) => [
                    'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $m['content'] ?? '',
                ], $messages)
            ),
        ];

        $body = Json::encode($payload);
        $url = $llm['url'];

        $lineBuffer = '';
        $wrote = false;
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
            CURLOPT_HTTPHEADER => self::buildRequestHeaders($llm),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$lineBuffer, &$wrote, $ensureHeaders) {
                $lineBuffer .= $chunk;
                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    $text = self::extractOpenAiDelta($line);
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
            CURLOPT_TIMEOUT => 120,
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlHelper::close($ch);

        if ($ok === false) {
            throw new \RuntimeException($error ?: 'Ошибка запроса к LLM');
        }
        if ($lineBuffer !== '') {
            $text = self::extractOpenAiDelta($lineBuffer);
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
            $provider = Config::aiProvider();
            ApiErrorLog::write($provider, 'chat.stream', $status, ApiErrorLog::formatHttpError($status, trim($lineBuffer), $provider));
            throw new \RuntimeException("LLM HTTP {$status}");
        }

        if (!$wrote) {
            throw new \RuntimeException('Модель вернула пустой ответ. Попробуйте ещё раз.');
        }
    }

    private static function extractOpenAiDelta(string $line): string
    {
        $line = trim($line);
        if ($line === '' || strpos($line, 'data:') !== 0) {
            return '';
        }
        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') {
            return '';
        }
        try {
            $json = Json::decode($data);
        } catch (\Throwable $e) {
            return '';
        }

        return (string)($json['choices'][0]['delta']['content'] ?? '');
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function complete(
        array $llm,
        string $systemPrompt,
        array $messages,
        int $maxTokens = 512
    ): string {
        $payload = [
            'model' => $llm['model'],
            'stream' => false,
            'temperature' => 0.4,
            'max_tokens' => $maxTokens,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                array_map(static fn($m) => [
                    'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $m['content'] ?? '',
                ], $messages)
            ),
        ];

        $ch = curl_init($llm['url']);
        CurlHelper::applySslOptions($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => self::buildRequestHeaders($llm),
            CURLOPT_POSTFIELDS => Json::encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlHelper::close($ch);

        if ($raw === false) {
            throw new \RuntimeException($error ?: 'Ошибка запроса к LLM');
        }
        if ($status >= 400) {
            $provider = $llm['provider'] ?? Config::aiProvider();
            ApiErrorLog::write($provider, 'chat.complete', $status, ApiErrorLog::formatHttpError($status, (string)$raw, $provider));
            throw new \RuntimeException("LLM HTTP {$status}");
        }

        try {
            $json = Json::decode($raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Некорректный ответ LLM');
        }

        $text = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Модель вернула пустой ответ');
        }

        return $text;
    }

    public static function isQuotaOrRateLimitError(\Throwable $err): bool
    {
        $raw = mb_strtolower($err->getMessage());

        return (bool)preg_match(
            '/\b429\b|\b402\b|quota|billing|insufficient balance|resource_exhausted|rate.?limit|too many requests|exceeded.*limit/i',
            $raw
        );
    }

    /**
     * Резервный LLM, если основной Gemini упёрся в квоту (нужен DEEPSEEK ключ).
     *
     * @return array{url: string, apiKey: string, model: string, provider: string}|null
     */
    public static function createFallbackLlm(): ?array
    {
        if (Config::aiProvider() !== 'gemini') {
            return null;
        }

        $deepseek = trim(Config::get('DEEPSEEK_API_KEY', ''));
        if ($deepseek !== '') {
            return [
                'provider' => 'deepseek',
                'apiKey' => $deepseek,
                'model' => Config::get('DEEPSEEK_MODEL', 'deepseek-chat'),
                'url' => 'https://api.deepseek.com/chat/completions',
            ];
        }

        return null;
    }

    public static function formatError(\Throwable $err, bool $forAdmin = false): array
    {
        $classified = self::classifyError($err);

        return [
            'status' => $classified['status'],
            'code' => $classified['code'],
            'error' => $forAdmin
                ? $classified['adminMessage']
                : self::publicErrorMessage($classified['code'], $err),
        ];
    }

    public static function publicErrorMessage(string $code, ?\Throwable $err = null): string
    {
        $fromSettings = Settings::message('error_' . strtolower($code));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        $raw = $err !== null ? $err->getMessage() : '';
        $isStt = $raw !== '' && (bool)preg_match('/\bstt\b|transcri|распозна/i', $raw);
        $isTts = $raw !== '' && (bool)preg_match('/\btts\b|озвуч/i', $raw);

        if ($isStt) {
            if ($code === 'QUOTA_EXCEEDED') {
                return 'Не удалось распознать речь. Подождите минуту или введите сообщение текстом.';
            }

            return 'Не удалось распознать речь. Попробуйте ещё раз или введите сообщение текстом.';
        }
        if ($isTts) {
            return 'Озвучка временно недоступна.';
        }

        switch ($code) {
            case 'QUOTA_EXCEEDED':
                return 'Сейчас консультант перегружен. Подождите минуту и попробуйте снова, или напишите текстом.';
            case 'API_DISABLED':
            case 'INVALID_KEY':
                return 'Консультант временно недоступен. Попробуйте позже.';
            case 'STT_ERROR':
                return 'Не удалось распознать речь. Попробуйте ещё раз или введите сообщение текстом.';
            case 'TTS_ERROR':
                return 'Озвучка временно недоступна.';
            default:
                return 'Не удалось получить ответ. Попробуйте ещё раз.';
        }
    }

    /**
     * @return array{status: int, code: string, adminMessage: string}
     */
    private static function classifyError(\Throwable $err): array
    {
        $raw = $err->getMessage();
        $provider = Config::aiProvider();

        if (strpos($raw, '403') !== false
            || preg_match('/has not been used in project|API has not been enabled|PERMISSION_DENIED/i', $raw)
        ) {
            return [
                'status' => 403,
                'code' => 'API_DISABLED',
                'adminMessage' => 'Gemini API не включён для этого ключа. Создайте ключ на https://aistudio.google.com/apikey',
            ];
        }

        if (self::isQuotaOrRateLimitError($err)) {
            $providerName = CustomProviders::displayName($provider);
            $billingUrl = $provider === 'gemini'
                ? 'https://aistudio.google.com/apikey'
                : ($provider === 'deepseek'
                    ? 'https://platform.deepseek.com/top_up'
                    : ($provider === 'perplexity'
                        ? 'https://www.perplexity.ai/settings/api'
                        : ''));
            $hint = preg_match('/rate.?limit|too many|429/i', $raw)
                ? ' Слишком много запросов подряд — подождите 30–60 сек. Голосовой ввод делает отдельный запрос на распознавание.'
                : '';
            $billingPart = $billingUrl !== '' ? " Проверьте квоту: {$billingUrl}" : '';

            return [
                'status' => 402,
                'code' => 'QUOTA_EXCEEDED',
                'adminMessage' => "Лимит или баланс {$providerName} исчерпан.{$billingPart}{$hint}",
            ];
        }

        if (strpos($raw, '401') !== false || preg_match('/invalid.*api.*key|incorrect api key/i', $raw)) {
            return [
                'status' => 401,
                'code' => 'INVALID_KEY',
                'adminMessage' => 'Неверный API-ключ. Проверьте настройки модуля AI-чат.',
            ];
        }

        return [
            'status' => 500,
            'code' => 'ERROR',
            'adminMessage' => $raw,
        ];
    }
}
