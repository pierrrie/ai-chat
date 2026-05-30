<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class ChatHandler
{
    private string $captured = '';

    /** @var array{sessionId: string, messages: array, relevant: array, tracking: array}|null */
    private ?array $leadDeferred = null;

    public function handleHealth(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $crm = [
                'enabled' => Config::isBitrix24LeadEnabled(),
                'hasWebhook' => trim(Config::get('BITRIX24_WEBHOOK_URL', '')) !== '',
                'configLoaded' => SiteConfig::isLoaded(),
                'configPath' => SiteConfig::loadedPath(),
                'sslVerify' => Settings::sslVerify(),
            ];
            if (!empty($_GET['crm_test'])) {
                $crm['ping'] = Bitrix24Lead::ping();
            }

            echo Json::encode([
                'ok' => true,
                'provider' => Config::aiProvider(),
                'model' => Config::currentModel(),
                'hasApiKey' => Config::hasLlmApiKey(),
                'chatLogEnabled' => Config::isChatLogEnabled(),
                'bitrix24Lead' => $crm,
                'catalog' => Catalog::getInfo(),
                'catalogSearch' => 'ai',
                'voice' => VoiceService::status(),
                'widget' => [
                    'enabled' => Settings::isWidgetEnabled(),
                    'voice' => Settings::isVoiceEnabled(),
                    'voiceReply' => Settings::isVoiceReplyEnabled(),
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo Json::encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'HEALTH_ERROR',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function handleTranscribe(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Settings::isVoiceEnabled()) {
            $this->jsonError(403, 'Голосовой ввод отключён', 'VOICE_DISABLED');
            return;
        }

        if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
            $this->jsonError(400, 'Нужен файл audio', 'NO_AUDIO');
            return;
        }

        try {
            $mime = (string)($_FILES['audio']['type'] ?? 'audio/webm');
            $result = VoiceService::transcribe($_FILES['audio']['tmp_name'], $mime);
            echo Json::encode(['ok' => true, 'text' => $result['text'], 'provider' => $result['provider']], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->jsonError(500, $e->getMessage(), 'STT_ERROR');
        }
    }

    public function handleTts(): void
    {
        if (!Settings::isVoiceReplyEnabled()) {
            $this->jsonError(403, 'Голосовой ответ отключён', 'VOICE_REPLY_DISABLED');
            return;
        }

        $raw = file_get_contents('php://input');
        $body = $raw ? json_decode($raw, true) : [];
        $text = trim((string)($body['text'] ?? $_POST['text'] ?? ''));
        if ($text === '') {
            $this->jsonError(400, 'Нужен text', 'NO_TEXT');
            return;
        }

        try {
            $result = TtsService::synthesize($text);
            if (($result['mode'] ?? '') === 'browser') {
                header('Content-Type: application/json; charset=utf-8');
                echo Json::encode([
                    'ok' => true,
                    'mode' => 'browser',
                    'text' => $result['text'] ?? '',
                ], JSON_UNESCAPED_UNICODE);

                return;
            }
            header('Content-Type: ' . ($result['contentType'] ?? 'audio/mpeg'));
            header('Cache-Control: no-store');
            echo $result['audio'] ?? '';
        } catch (\Throwable $e) {
            $this->jsonError(500, $e->getMessage(), 'TTS_ERROR');
        }
    }

    public function handleFaqPreview(array $body): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $question = trim((string)($body['question'] ?? ''));
        if ($question === '') {
            $this->jsonError(400, 'Нужен question', 'NO_QUESTION');

            return;
        }

        echo Json::encode(FaqKnowledge::adminPreview($question), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function handleChat(array $body): void
    {
        $messages = $this->normalizeMessages($body['messages'] ?? null);
        if (!$messages) {
            $this->jsonError(400, 'Нужен массив messages', 'BAD_REQUEST');
            return;
        }

        $sessionId = $this->resolveSessionId($body['sessionId'] ?? null);
        header('X-Chat-Session-Id: ' . $sessionId);
        ChatLog::ensureSession($sessionId);

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]['role'] === 'user') {
                $lastUser = $messages[$i]['content'];
                break;
            }
        }

        $detailed = ContextSearch::wantsDetailedAnswer($lastUser, $messages);
        $allProducts = Catalog::getAllProducts();
        $tracking = $this->extractTracking($body);
        $apiMessages = ChatApi::trimMessagesForApi($messages);
        $maxTokens = ChatApi::outputTokenLimit($detailed);

        if (LeadFlow::isPhoneOnlyUserMessage($lastUser)) {
            $leadMeta = ChatLog::getLeadMeta($sessionId);
            $leadResult = $leadMeta['created'] ? ['ok' => true, 'leadId' => $leadMeta['leadId'], 'contactId' => $leadMeta['contactId'], 'error' => null] : LeadFlow::tryCreateLead($sessionId, $messages, [], $tracking);
            $phone = Phone::extractFromMessages($messages) ?? $lastUser;
            $displayPhone = $phone;
            if (str_starts_with($phone, '+7') && strlen($phone) === 12) {
                $displayPhone = '+7 ' . substr($phone, 2, 3) . ' ' . substr($phone, 5, 3) . '-' . substr($phone, 8, 2) . '-' . substr($phone, 10, 2);
            }
            $brand = Config::brand();
            $leadOk = is_array($leadResult) && !empty($leadResult['ok']);
            if ($leadOk) {
                $this->signalLeadCreatedToClient(false);
                $reply = Settings::message('phone_reply_ok', ['phone' => $displayPhone, 'brand' => $brand])
                    ?: "Спасибо! Записал номер {$displayPhone}. Менеджер {$brand} перезвонит и поможет с выбором и оформлением.";
            } else {
                $reply = Settings::message('phone_reply_fallback', ['phone' => $displayPhone, 'brand' => $brand])
                    ?: "Спасибо! Номер {$displayPhone} принят — менеджер {$brand} свяжется с вами.";
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo $reply;
            $logErr = $leadOk ? null : (is_array($leadResult) ? ($leadResult['error'] ?? 'crm_failed') : 'crm_failed');
            ChatLog::appendTurn($sessionId, $lastUser, $reply, false, [], $logErr);
            return;
        }

        $this->leadDeferred = [
            'sessionId' => $sessionId,
            'messages' => $messages,
            'relevant' => [],
            'tracking' => $tracking,
        ];

        $provider = Config::aiProvider();
        if ($provider === 'gemini') {
            if (Config::get('GEMINI_API_KEY') === '') {
                $this->jsonError(500, 'Не задан GEMINI_API_KEY в настройках модуля.', 'NO_API_KEY');
                $this->finish($sessionId, $lastUser, [], $detailed, 'NO_API_KEY');
                return;
            }
        } else {
            try {
                LlmClient::create();
            } catch (\RuntimeException $e) {
                $provider = Config::aiProvider();
                if (Config::isBuiltInAiProvider($provider)) {
                    $keyName = $provider === 'perplexity'
                        ? 'PERPLEXITY_API_KEY'
                        : 'DEEPSEEK_API_KEY';
                } else {
                    $keyName = 'API-ключ провайдера «' . CustomProviders::displayName($provider) . '»';
                }
                $this->jsonError(500, "Не задан {$keyName} в настройках модуля.", 'NO_API_KEY');
                $this->finish($sessionId, $lastUser, [], $detailed, 'NO_API_KEY');
                return;
            }
        }

        ChatApi::beginStream();

        $systemPrompt = Prompts::buildSystemPrompt(
            Config::shopName(),
            Config::siteUrl(),
            $detailed,
            $sessionId,
            $messages
        );

        $this->captured = '';
        ob_start(function (string $chunk) {
            $this->captured .= $chunk;
            return $chunk;
        }, 1);

        try {
            if ($provider === 'gemini') {
                try {
                    $this->streamGeminiChat(
                        $sessionId,
                        $systemPrompt,
                        $apiMessages,
                        $detailed,
                        $messages
                    );
                } catch (\Throwable $e) {
                    $this->streamError($e);
                    $this->finish($sessionId, $lastUser, [], $detailed, 'LLM_API_ERROR');
                    return;
                }
                $this->finish($sessionId, $lastUser, [], $detailed);
                return;
            }

            try {
                $llm = LlmClient::create();
                LlmClient::streamOpenAiCompatible($llm, $systemPrompt, $apiMessages, $maxTokens);
                if (LeadFlow::shouldAppendLeadCta($sessionId, $messages, $this->captured)) {
                    $cta = LeadFlow::leadPhoneCtaSuffix($this->captured);
                    echo $cta;
                    $this->captured .= $cta;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                $this->streamError($e);
                $this->finish($sessionId, $lastUser, [], $detailed, 'LLM_API_ERROR');
                return;
            }

            $this->finish($sessionId, $lastUser, [], $detailed);
        } finally {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    }

    /**
     * @param Product[] $relevant
     */
    private function finish(
        string $sessionId,
        string $userMessage,
        array $relevant,
        bool $detailed,
        ?string $error = null
    ): void {
        $assistant = $this->captured;
        if ($assistant === '' && ob_get_level() > 0) {
            $assistant = ob_get_contents() ?: '';
        }
        ChatLog::appendTurn($sessionId, $userMessage, $assistant, $detailed, $relevant, $error);

        if ($this->leadDeferred !== null && $this->leadDeferred['sessionId'] === $sessionId) {
            $ctx = $this->leadDeferred;
            $this->leadDeferred = null;
            $leadResult = LeadFlow::tryCreateLead(
                $ctx['sessionId'],
                $ctx['messages'],
                $ctx['relevant'],
                $ctx['tracking']
            );
            if (is_array($leadResult) && !empty($leadResult['ok'])) {
                $this->signalLeadCreatedToClient(true);
            }
        }
    }

    /** Сигнал виджету: лид создан — клиент вызывает ym(..., reachGoal, ...). */
    private function signalLeadCreatedToClient(bool $appendStreamMarker): void
    {
        if (!headers_sent()) {
            header('X-Chat-Lead-Created: 1');
        }
        if (!$appendStreamMarker) {
            return;
        }
        $marker = Settings::leadCreatedStreamMarker();
        echo $marker;
        $this->captured .= $marker;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @param mixed $raw
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeMessages($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? '';
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    private function resolveSessionId($raw): string
    {
        if (is_string($raw) && preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $raw)) {
            return $raw;
        }
        return 's_' . time() . '_' . bin2hex(random_bytes(4));
    }

    private function jsonError(int $status, string $error, string $code = 'ERROR'): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => $error, 'code' => $code], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function appendStreamEnrichment(array $messages): void
    {
        // Отключено: бот сам вставляет ссылки в текст ответа.
    }

    /**
     * @param array<int, array{role: string, content: string}> $apiMessages
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function streamGeminiChat(
        string $sessionId,
        string $systemPrompt,
        array $apiMessages,
        bool $detailed,
        array $messages
    ): void {
        try {
            GeminiChat::streamNative(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.0-flash'),
                $systemPrompt,
                $apiMessages,
                $detailed
            );
        } catch (\Throwable $e) {
            $fallback = LlmClient::isQuotaOrRateLimitError($e) ? LlmClient::createFallbackLlm() : null;
            if ($fallback === null) {
                throw $e;
            }
            LlmClient::streamOpenAiCompatible(
                $fallback,
                $systemPrompt,
                $apiMessages,
                ChatApi::outputTokenLimit($detailed)
            );
        }

        if (LeadFlow::shouldAppendLeadCta($sessionId, $messages, $this->captured)) {
            $cta = LeadFlow::leadPhoneCtaSuffix($this->captured);
            echo $cta;
            $this->captured .= $cta;
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
    }

    private function streamError(\Throwable $e): void
    {
        $formatted = LlmClient::formatError($e);
        $msg = $formatted['error'];
        if (!headers_sent()) {
            $this->jsonError($formatted['status'], $msg, $formatted['code'] ?? 'LLM_API_ERROR');
            return;
        }
        echo "\n\n" . $msg;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string|null>
     */
    private function extractTracking(array $body): array
    {
        $raw = $body['tracking'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $keys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            '_ym_uid', 'http_referer', 'page_url', 'page_title',
        ];
        $out = [];
        foreach ($keys as $key) {
            $val = isset($raw[$key]) ? trim((string)$raw[$key]) : '';
            $out[$key] = $val !== '' ? $val : null;
        }
        return $out;
    }
}
