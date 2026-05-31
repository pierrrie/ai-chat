<?php



namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class TtsService

{

    /**

     * @return array{mode: string, text?: string}

     */

    public static function synthesize(string $text): array
    {
        $plain = self::preparePlainText($text);
        $provider = Settings::resolveVoiceTtsProvider();
        if ($provider === 'browser') {
            return ['mode' => 'browser', 'text' => $plain];
        }
        if (str_starts_with($provider, 'custom:')) {
            $customId = substr($provider, 7);
            $cfg = CustomVoiceProviders::getTtsById($customId);
            if ($cfg === null) {
                throw new \RuntimeException('Провайдер озвучки не найден: ' . $customId);
            }

            switch ($cfg['kind']) {
                case 'openai_speech':
                case 'speech_json':
                    return self::synthesizeOpenAiSpeech($plain, $cfg);
                case 'http_audio':
                    return self::synthesizeHttpAudio($plain, $cfg);
                default:
                    throw new \RuntimeException('Неизвестный тип TTS: ' . $cfg['kind']);
            }
        }

        return ['mode' => 'browser', 'text' => $plain];
    }

    public static function isConfigured(): bool
    {
        try {
            Settings::resolveVoiceTtsProvider();

            return true;
        } catch (\Throwable $e) {
            return Settings::voiceTtsProvider() === 'browser';
        }
    }

    /**
     * @param array<string, mixed> $provider
     * @return array{mode: string, audio: string, contentType: string}
     */
    private static function synthesizeOpenAiSpeech(string $plain, array $provider): array
    {
        $key = trim((string)($provider['api_key'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('Укажите API-ключ для TTS-провайдера');
        }

        $baseUrl = rtrim(trim((string)($provider['url'] ?? '')), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://api.openai.com/v1';
        }
        $url = str_contains($baseUrl, '/audio/speech') ? $baseUrl : $baseUrl . '/audio/speech';
        $model = trim((string)($provider['model'] ?? ''));
        if ($model === '') {
            $model = 'tts-1';
        }
        $voice = trim((string)($provider['voice'] ?? ''));
        if ($voice === '') {
            $voice = 'alloy';
        }
        $auth = (string)($provider['auth'] ?? 'bearer');
        $extraHeaders = (array)($provider['extra_headers'] ?? []);

        $payload = Json::encode([
            'model' => $model,
            'input' => $plain,
            'voice' => $voice,
        ]);

        $headers = ['Content-Type: application/json'];
        if ($auth === 'api-key') {
            $headers[] = 'Authorization: Api-Key ' . $key;
        } elseif ($auth !== 'none') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120,
        ]);
        CurlHelper::applySslOptions($ch);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        CurlHelper::close($ch);

        if ($code === 200 && is_string($body) && $body !== '') {
            return [
                'mode' => 'audio',
                'audio' => $body,
                'contentType' => $contentType !== '' ? $contentType : 'audio/mpeg',
            ];
        }

        $detail = '';
        if (is_string($body) && $body !== '') {
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['error']['message'])) {
                $detail = ': ' . (string)$json['error']['message'];
            }
        }

        throw new \RuntimeException('TTS error HTTP ' . $code . $detail);
    }

    /**
     * @param array<string, mixed> $provider
     * @return array{mode: string, audio: string, contentType: string}
     */
    private static function synthesizeHttpAudio(string $plain, array $provider): array
    {
        $url = trim((string)($provider['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Укажите URL API для TTS-провайдера');
        }

        $key = trim((string)($provider['api_key'] ?? ''));
        $auth = (string)($provider['auth'] ?? 'api-key');
        $extraHeaders = (array)($provider['extra_headers'] ?? []);
        $model = trim((string)($provider['model'] ?? ''));
        $voice = trim((string)($provider['voice'] ?? ''));
        $lang = trim((string)($provider['lang'] ?? 'ru-RU'));

        $payload = Json::encode(array_filter([
            'text' => $plain,
            'input' => $plain,
            'model' => $model !== '' ? $model : null,
            'voice' => $voice !== '' ? $voice : null,
            'lang' => $lang !== '' ? $lang : null,
        ], static fn($v) => $v !== null && $v !== ''));

        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            if ($auth === 'api-key') {
                $headers[] = 'Authorization: Api-Key ' . $key;
            } elseif ($auth !== 'none') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120,
        ]);
        CurlHelper::applySslOptions($ch);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        CurlHelper::close($ch);

        if ($code === 200 && is_string($body) && $body !== '') {
            $ct = $contentType !== '' ? $contentType : 'audio/mpeg';
            if (str_contains(strtolower($ct), 'json')) {
                $json = json_decode($body, true);
                if (is_array($json) && !empty($json['audio']) && is_string($json['audio'])) {
                    $decoded = base64_decode($json['audio'], true);
                    if (is_string($decoded) && $decoded !== '') {
                        return [
                            'mode' => 'audio',
                            'audio' => $decoded,
                            'contentType' => 'audio/mpeg',
                        ];
                    }
                }
            }

            return [
                'mode' => 'audio',
                'audio' => $body,
                'contentType' => $ct,
            ];
        }

        $detail = is_string($body) && $body !== '' ? ': ' . mb_substr($body, 0, 200) : '';

        throw new \RuntimeException('TTS HTTP error ' . $code . $detail);
    }

    public static function preparePlainText(string $text): string
    {
        $max = Settings::voiceTtsMaxChars();
        $plain = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
        $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*\*([^*]+)\*\*/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*([^*\n]+)\*/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/`([^`]+)`/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/^#{1,6}\s+/m', '', $plain) ?? $plain;
        $plain = preg_replace('/^[\-*•]\s+/m', '', $plain) ?? $plain;
        $plain = self::normalizeTextForTts($plain);
        $plain = preg_replace('/(?<!\w)\*(?!\w)/u', '', $plain) ?? $plain;
        $plain = strip_tags($plain);
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $plain = trim($plain);
        if (mb_strlen($plain) > $max) {
            $plain = rtrim(mb_substr($plain, 0, $max)) . '…';
        }

        return $plain;
    }

    /** Keep in sync with plainTextForTts() in aichat.chat script.js */
    private static function normalizeTextForTts(string $text): string
    {
        $s = $text;
        $s = preg_replace_callback(
            '/(\d[\d\s\x{00a0}]*)\s*(?:₽|руб\.?|RUB|rub)(?:[\s.,;]|$)/iu',
            static function (array $m): string {
                $num = (int)preg_replace('/[\s\x{00a0}]+/u', '', $m[1]);
                return self::formatRussianNumberSpeakable($num) . ' рублей ';
            },
            $s
        ) ?? $s;
        $s = preg_replace_callback(
            '/\d{1,3}(?:[\s\x{00a0}]\d{3})+(?:[.,]\d+)?/u',
            static function (array $m): string {
                return preg_replace('/[\s\x{00a0}]+/u', '', $m[0]) ?? $m[0];
            },
            $s
        ) ?? $s;
        $s = preg_replace('/(\d)\s*[\*×xX]\s*(\d)/u', '$1 на $2', $s) ?? $s;
        $s = preg_replace('/[×]/u', ' на ', $s) ?? $s;
        $s = preg_replace('/[–—−]/u', '-', $s) ?? $s;
        $s = preg_replace('/^\s*\|.*\|\s*$/m', ' ', $s) ?? $s;
        $s = preg_replace('/\|/u', ' ', $s) ?? $s;
        $s = preg_replace('/^>\s+/m', '', $s) ?? $s;
        $s = preg_replace('/\*+/u', '', $s) ?? $s;

        return $s;
    }

    private static function pluralRu(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }

    private static function formatRussianNumberSpeakable(int $n): string
    {
        if ($n < 0) {
            return (string)$n;
        }
        if ($n >= 1000000) {
            $millions = intdiv($n, 1000000);
            $s = $millions . ' ' . self::pluralRu($millions, 'миллион', 'миллиона', 'миллионов');
            $n %= 1000000;
            if ($n >= 1000) {
                $thousands = intdiv($n, 1000);
                $s .= ' ' . $thousands . ' ' . self::pluralRu($thousands, 'тысяча', 'тысячи', 'тысяч');
                $n %= 1000;
            }
            if ($n > 0) {
                $s .= ' ' . $n;
            }

            return $s;
        }
        if ($n >= 1000) {
            $thousands = intdiv($n, 1000);
            $s = $thousands . ' ' . self::pluralRu($thousands, 'тысяча', 'тысячи', 'тысяч');
            $n %= 1000;
            if ($n > 0) {
                $s .= ' ' . $n;
            }

            return $s;
        }

        return (string)$n;
    }

}

