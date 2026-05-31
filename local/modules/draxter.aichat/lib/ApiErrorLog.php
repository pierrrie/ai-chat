<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class ApiErrorLog
{
    private static ?string $requestSessionId = null;

    public static function setRequestSessionId(?string $sessionId): void
    {
        $id = trim((string)$sessionId);
        self::$requestSessionId = $id !== '' ? $id : null;
    }

    public static function clearRequestSessionId(): void
    {
        self::$requestSessionId = null;
    }

    public static function write(
        string $provider,
        string $action,
        int $httpCode,
        string $message,
        ?string $sessionId = null
    ): void {
        $dir = ChatLog::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $session = trim((string)($sessionId ?? self::$requestSessionId ?? ''));

        $line = date('Y-m-d H:i:s')
            . "\t" . $provider
            . "\t" . $action
            . "\t" . $httpCode
            . "\t" . str_replace(["\r", "\n", "\t"], ' ', $message)
            . "\t" . $session
            . "\n";

        file_put_contents(self::pathForDate(date('Y-m-d')), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Человекочитаемое сообщение для записи в лог при HTTP-ошибке.
     */
    public static function formatHttpError(int $httpCode, string $rawBody, string $provider = ''): string
    {
        $detail = self::extractErrorDetail($rawBody);
        $summary = self::httpCodeSummary($httpCode, $provider);

        if ($detail !== '') {
            return $summary . ' — ' . $detail;
        }

        return $summary;
    }

    public static function pathForDate(string $date): string
    {
        return ChatLog::logDir() . '/api-' . $date . '.log';
    }

    /**
     * @return string[]
     */
    public static function listLogFiles(): array
    {
        $dir = ChatLog::logDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/api-*.log') ?: [];
        rsort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    public static function tailLines(string $filePath, int $limit = 200, ?string $filter = null): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];
        $lines = array_reverse($lines);
        if ($filter !== null && $filter !== '') {
            $filter = mb_strtolower($filter);
            $lines = array_values(array_filter(
                $lines,
                static fn($line) => str_contains(mb_strtolower($line), $filter)
            ));
        }

        return array_slice($lines, 0, $limit);
    }

    /**
     * @return array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $parts = explode("\t", $line, 6);
        if (count($parts) < 5) {
            return null;
        }

        return [
            'time' => $parts[0],
            'provider' => $parts[1],
            'action' => $parts[2],
            'httpCode' => (int)$parts[3],
            'message' => $parts[4],
            'sessionId' => $parts[5] ?? '',
        ];
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string}>
     */
    public static function parseLines(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    /**
     * @param array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string} $row
     * @return array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string, providerLabel: string, actionLabel: string, errorType: string, detail: string, rowClass: string, httpClass: string}
     */
    public static function enrichRow(array $row): array
    {
        $provider = (string)($row['provider'] ?? '');
        $action = (string)($row['action'] ?? '');
        $httpCode = (int)($row['httpCode'] ?? 0);
        $message = trim((string)($row['message'] ?? ''));

        $detail = self::resolveDisplayDetail($message, $httpCode, $provider);
        $errorType = self::resolveErrorType($httpCode, $provider, $action, $message, $detail);

        return array_merge($row, [
            'providerLabel' => self::providerLabel($provider),
            'actionLabel' => self::actionLabel($action),
            'errorType' => $errorType,
            'detail' => $detail,
            'rowClass' => self::rowClass($httpCode, $errorType),
            'httpClass' => self::httpClass($httpCode),
        ]);
    }

    /**
     * @param array<int, array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string}> $rows
     * @return array<int, array{time: string, provider: string, action: string, httpCode: int, message: string, sessionId: string, providerLabel: string, actionLabel: string, errorType: string, detail: string, rowClass: string, httpClass: string}>
     */
    public static function enrichRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::enrichRow($row);
        }

        return $out;
    }

    public static function providerLabel(string $provider): string
    {
        $map = [
            'gemini' => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'perplexity' => 'Perplexity',
            'bitrix24' => 'Bitrix24 REST',
            'bitrix_crm' => 'Bitrix CRM',
            'email' => 'Email',
        ];
        $key = strtolower(trim($provider));
        if (isset($map[$key])) {
            return $map[$key];
        }

        $custom = CustomProviders::displayName($provider);

        return $custom !== $provider ? $custom : $provider;
    }

    public static function actionLabel(string $action): string
    {
        $map = [
            'chat.stream' => 'Потоковый чат',
            'chat.complete' => 'Запрос к чату',
            'lead.add' => 'Создание лида',
            'lead.mail' => 'Письмо с лидом',
            'voice.transcribe' => 'Распознавание речи',
            'voice.tts' => 'Синтез речи',
        ];
        $key = strtolower(trim($action));
        if (isset($map[$key])) {
            return $map[$key];
        }

        return $action;
    }

    public static function httpCodeSummary(int $httpCode, string $provider = ''): string
    {
        $providerKey = strtolower(trim($provider));
        $isGemini = $providerKey === 'gemini';

        if ($httpCode === 429) {
            return $isGemini
                ? 'Лимит запросов Gemini (quota exceeded)'
                : 'Превышен лимит запросов (429)';
        }
        if ($httpCode === 401) {
            return $isGemini
                ? 'Неверный ключ Gemini API'
                : 'Ошибка авторизации API (401)';
        }
        if ($httpCode === 403) {
            return 'Доступ запрещён (403)';
        }
        if ($httpCode === 404) {
            return 'Ресурс не найден (404)';
        }
        if ($httpCode === 503) {
            return $isGemini
                ? 'Gemini временно недоступен (503)'
                : 'Сервис временно недоступен (503)';
        }
        if ($httpCode >= 500) {
            return $isGemini
                ? 'Ошибка сервера Gemini (' . $httpCode . ')'
                : 'Ошибка сервера провайдера (' . $httpCode . ')';
        }
        if ($httpCode >= 400) {
            return 'HTTP-ошибка (' . $httpCode . ')';
        }
        if ($httpCode === 0) {
            return 'Сетевая или внутренняя ошибка';
        }

        return 'HTTP ' . $httpCode;
    }

    public static function purgeOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $cutoff = strtotime('-' . $days . ' days');
        $removed = 0;
        foreach (self::listLogFiles() as $file) {
            if (preg_match('/api-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $ts = strtotime($m[1] . ' 00:00:00');
                if ($ts !== false && $ts < $cutoff && @unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private static function resolveErrorType(
        int $httpCode,
        string $provider,
        string $action,
        string $message,
        string $detail
    ): string {
        $haystack = mb_strtolower($message . ' ' . $detail);

        if ($httpCode === 429 || self::looksLikeQuotaError($haystack)) {
            return strtolower($provider) === 'gemini'
                ? 'Лимит Gemini'
                : 'Лимит запросов';
        }
        if ($httpCode === 401 || str_contains($haystack, 'api key') || str_contains($haystack, 'unauthorized')) {
            return 'Авторизация';
        }
        if ($httpCode === 403) {
            return 'Доступ запрещён';
        }
        if ($httpCode === 503 || str_contains($haystack, 'unavailable') || str_contains($haystack, 'overloaded')) {
            return 'Сервис недоступен';
        }
        if ($httpCode >= 500) {
            return 'Ошибка сервера';
        }
        if ($httpCode === 0) {
            if (str_contains($action, 'lead') || str_contains($action, 'mail')) {
                return 'Ошибка интеграции';
            }

            return 'Внутренняя ошибка';
        }
        if (str_contains($haystack, 'json error') || str_contains($haystack, 'некорректный ответ')) {
            return 'Некорректный ответ';
        }

        return 'HTTP-ошибка';
    }

    private static function resolveDisplayDetail(string $message, int $httpCode, string $provider): string
    {
        $generic = [
            'http error',
            'llm http error',
            'json error',
            'mail::send failed',
        ];
        $normalized = mb_strtolower(trim($message));

        if ($message === '' || in_array($normalized, $generic, true)) {
            if ($httpCode === 429) {
                return strtolower($provider) === 'gemini'
                    ? 'Исчерпана квота или превышен rate limit. Подождите 1–2 минуты или проверьте лимиты в Google AI Studio.'
                    : 'Слишком много запросов. Подождите и повторите.';
            }

            return '';
        }

        $extracted = self::extractErrorDetail($message);
        if ($extracted !== '') {
            return $extracted;
        }

        if (mb_strlen($message) > 320) {
            return mb_substr($message, 0, 317) . '…';
        }

        return $message;
    }

    private static function extractErrorDetail(string $rawBody): string
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            return '';
        }

        $jsonText = self::unwrapJsonPayload($rawBody);
        if ($jsonText !== null) {
            try {
                $json = Json::decode($jsonText);
            } catch (\Throwable $e) {
                $json = null;
            }
            if (is_array($json)) {
                $parts = [];
                if (isset($json['error']) && is_array($json['error'])) {
                    $err = $json['error'];
                    if (!empty($err['status'])) {
                        $parts[] = (string)$err['status'];
                    }
                    if (!empty($err['message'])) {
                        $parts[] = (string)$err['message'];
                    }
                    if (!empty($err['code']) && !in_array((string)$err['code'], $parts, true)) {
                        array_unshift($parts, (string)$err['code']);
                    }
                } elseif (!empty($json['message'])) {
                    $parts[] = (string)$json['message'];
                } elseif (!empty($json['detail'])) {
                    $parts[] = is_string($json['detail']) ? $json['detail'] : Json::encode($json['detail']);
                }

                $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
                if ($parts !== []) {
                    $detail = implode(': ', $parts);
                    if (mb_strlen($detail) > 320) {
                        return mb_substr($detail, 0, 317) . '…';
                    }

                    return $detail;
                }
            }
        }

        $plain = preg_replace('/\s+/u', ' ', $rawBody) ?? $rawBody;
        if (mb_strlen($plain) > 320) {
            return mb_substr($plain, 0, 317) . '…';
        }

        return $plain;
    }

    private static function unwrapJsonPayload(string $rawBody): ?string
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            return null;
        }
        if ($rawBody[0] === '{') {
            return $rawBody;
        }
        if (str_starts_with($rawBody, 'data:')) {
            $payload = trim(substr($rawBody, 5));
            if ($payload !== '' && $payload[0] === '{') {
                return $payload;
            }
        }
        if (preg_match('/\{[\s\S]*\}/', $rawBody, $m)) {
            return $m[0];
        }

        return null;
    }

    private static function looksLikeQuotaError(string $haystack): bool
    {
        return (bool)preg_match(
            '/\b429\b|quota|resource_exhausted|rate.?limit|too many requests|exceeded.*limit|исчерпан|квот/i',
            $haystack
        );
    }

    private static function rowClass(int $httpCode, string $errorType): string
    {
        if ($httpCode === 429 || $errorType === 'Лимит Gemini' || $errorType === 'Лимит запросов') {
            return 'draxter-logs-row--quota';
        }
        if ($httpCode >= 500) {
            return 'draxter-logs-row--error';
        }
        if ($httpCode >= 400 || $httpCode === 0) {
            return 'draxter-logs-row--warn';
        }

        return '';
    }

    private static function httpClass(int $httpCode): string
    {
        if ($httpCode === 429) {
            return 'draxter-logs-code--quota';
        }
        if ($httpCode >= 500) {
            return 'draxter-logs-code--error';
        }
        if ($httpCode >= 400 || $httpCode === 0) {
            return 'draxter-logs-code--warn';
        }

        return 'draxter-logs-code--ok';
    }
}
