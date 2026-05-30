<?php



namespace Draxter\Aichat;



use Bitrix\Main\Web\Json;



class VoiceService

{

    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;



    /** Если в настройках STT-модель пуста и GEMINI_MODEL не задан. */

    private const DEFAULT_GEMINI_STT_MODEL = 'gemini-2.5-flash';



    /**

     * @return array{text: string, provider: string}

     */

    public static function transcribe(string $tmpPath, string $mimeType): array

    {

        if (!is_readable($tmpPath)) {

            throw new \RuntimeException('Файл аудио не найден');

        }

        if (filesize($tmpPath) > self::MAX_UPLOAD_BYTES) {

            throw new \RuntimeException('Аудио слишком большое (макс. 5 МБ)');

        }



        self::resolveSttProvider();



        return ['text' => self::transcribeGemini($tmpPath, $mimeType), 'provider' => 'gemini'];

    }



    /**

     * @return array{enabled: bool, reply: bool, stt: string, tts: bool, ttsProvider: string, sttError?: string}

     */

    public static function status(): array

    {

        $stt = 'unconfigured';

        $sttError = null;

        try {

            $stt = self::resolveSttProvider();

        } catch (\Throwable $e) {

            $sttError = $e->getMessage();

        }



        $status = [

            'enabled' => Settings::isVoiceEnabled(),

            'reply' => Settings::isVoiceReplyEnabled(),

            'stt' => $stt,

            'tts' => TtsService::isConfigured(),

            'ttsProvider' => Settings::voiceTtsProvider(),

            'maxSeconds' => Settings::voiceMaxSeconds(),

        ];

        if ($sttError !== null) {

            $status['sttError'] = $sttError;

        }



        return $status;

    }



    private static function resolveSttProvider(): string

    {

        $pref = Settings::voiceSttProvider();

        if ($pref === 'gemini' || $pref === 'auto') {

            if (trim(Config::get('GEMINI_API_KEY', '')) === '') {

                throw new \RuntimeException('Нужен GEMINI_API_KEY для распознавания речи');

            }



            return 'gemini';

        }



        throw new \RuntimeException('Неизвестный STT провайдер: ' . $pref);

    }



    private static function transcribeGemini(string $tmpPath, string $mimeType): string

    {

        $lastError = null;

        foreach (self::geminiSttModelCandidates() as $model) {

            try {

                return self::transcribeGeminiWithModel($tmpPath, $mimeType, $model);

            } catch (\RuntimeException $e) {

                $lastError = $e;

                if (!self::isGeminiSttModelUnavailable($e)) {

                    throw $e;

                }

            }

        }



        throw $lastError ?? new \RuntimeException(

            'Gemini STT: ни одна модель не доступна. Укажите GEMINI STT модель = gemini-2.5-flash (как в чате).'

        );

    }



    private static function transcribeGeminiWithModel(string $tmpPath, string $mimeType, string $model): string

    {

        $key = trim(Config::get('GEMINI_API_KEY', ''));

        $audio = base64_encode((string)file_get_contents($tmpPath));

        $mime = self::normalizeAudioMime($mimeType);

        $url = self::geminiGenerateContentUrl($model, $key);



        $payload = Json::encode([

            'contents' => [[

                'role' => 'user',

                'parts' => [

                    ['text' => 'Распознай речь на русском языке. Верни только текст сказанного, без пояснений и кавычек.'],

                    ['inlineData' => ['mimeType' => $mime, 'data' => $audio]],

                ],

            ]],

            'generationConfig' => [

                'temperature' => 0.1,

                'maxOutputTokens' => 1024,

            ],

        ]);



        $lastError = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {

            if ($attempt > 0) {

                usleep(500000 * (2 ** ($attempt - 1)));

            }



            $ch = curl_init($url);

            curl_setopt_array($ch, [

                CURLOPT_POST => true,

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],

                CURLOPT_POSTFIELDS => $payload,

                CURLOPT_TIMEOUT => 120,

            ]);

            CurlHelper::applySslOptions($ch);

            $body = curl_exec($ch);

            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            CurlHelper::close($ch);



            if ($code === 200 && is_string($body)) {

                $data = json_decode($body, true);

                if (!is_array($data)) {

                    throw new \RuntimeException('Gemini STT: неверный ответ сервера');

                }

                if (!empty($data['error']['message'])) {

                    throw new \RuntimeException('Gemini STT: ' . (string)$data['error']['message']);

                }



                $parts = $data['candidates'][0]['content']['parts'] ?? [];

                $text = '';

                foreach ($parts as $part) {

                    if (isset($part['text'])) {

                        $text .= $part['text'];

                    }

                }

                $text = trim($text, " \t\n\r\0\x0B\"'");

                if ($text === '') {

                    throw new \RuntimeException('Gemini не вернул текст. Попробуйте другую STT-модель или говорите чётче.');

                }



                return $text;

            }



            $lastError = new \RuntimeException(

                self::formatGeminiSttError($code, $body, Config::geminiUseVertex(), $model)

            );

            if ($attempt >= 2 || ($code !== 429 && $code !== 503)) {

                throw $lastError;

            }

        }



        throw $lastError ?? new \RuntimeException('Gemini STT error');

    }



    /**

     * @return string[]

     */

    private static function geminiSttModelCandidates(): array

    {

        $out = [];

        $explicit = trim(Settings::get('VOICE_GEMINI_STT_MODEL', ''));

        if ($explicit !== '') {

            $out[] = $explicit;

        }

        $chatModel = trim(Config::get('GEMINI_MODEL', ''));

        if ($chatModel !== '') {

            $out[] = $chatModel;

        }

        foreach (['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-flash-002', 'gemini-1.5-pro-002'] as $fallback) {

            $out[] = $fallback;

        }

        $out[] = self::DEFAULT_GEMINI_STT_MODEL;



        return array_values(array_unique(array_filter($out)));

    }



    private static function isGeminiSttModelUnavailable(\RuntimeException $e): bool

    {

        $m = mb_strtolower($e->getMessage());



        return str_contains($m, '404')

            || str_contains($m, 'not found')

            || str_contains($m, 'was not found')

            || str_contains($m, 'invalid model');

    }



    private static function geminiGenerateContentUrl(string $model, string $apiKey): string

    {

        $encodedModel = rawurlencode($model);

        if (Config::geminiUseVertex()) {

            return 'https://aiplatform.googleapis.com/v1/publishers/google/models/'

                . $encodedModel

                . ':generateContent?key=' . rawurlencode($apiKey);

        }



        return 'https://generativelanguage.googleapis.com/v1beta/models/'

            . $encodedModel

            . ':generateContent?key=' . rawurlencode($apiKey);

    }



    private static function normalizeAudioMime(string $mimeType): string

    {

        $mime = strtolower(trim($mimeType));

        if ($mime === '') {

            return 'audio/webm';

        }

        if (str_contains($mime, ';')) {

            $mime = trim(explode(';', $mime)[0]);

        }

        $allowed = ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/mp3'];

        if (in_array($mime, $allowed, true)) {

            return $mime === 'audio/x-wav' ? 'audio/wav' : $mime;

        }



        return 'audio/webm';

    }



    private static function formatGeminiSttError(int $code, $body, bool $vertex, string $model = ''): string

    {

        $hint = $vertex

            ? ' Ключ Vertex (AQ.) работает через aiplatform.googleapis.com — проверьте GEMINI_USE_VERTEX и права ключа.'

            : ' Проверьте GEMINI_API_KEY и включённый Generative Language API в Google Cloud.';

        if ($code === 404) {

            $hint .= ' Укажите в настройках «Gemini STT модель» ту же, что в GEMINI_MODEL (например gemini-2.5-flash), или очистите поле STT — подставится модель чата.';

            if ($model !== '') {

                $hint .= ' Модель в запросе: ' . $model . '.';

            }

        }

        if ($code === 429) {

            $hint .= ' Подождите минуту — голос и чат на одном ключе Gemini быстро упираются в лимит.';

        }

        $detail = '';

        if (is_string($body) && $body !== '') {

            $json = json_decode($body, true);

            if (is_array($json) && !empty($json['error']['message'])) {

                $detail = ': ' . $json['error']['message'];

            }

        }



        return 'Gemini STT error HTTP ' . $code . $detail . $hint;

    }

}


