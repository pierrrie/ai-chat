<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class CustomVoiceProviders
{
    public const STT_RESERVED = ['auto', 'browser', 'gemini', 'openai', 'openai_whisper'];
    public const TTS_RESERVED = ['browser'];
    public const STT_KINDS = ['gemini', 'http'];
    public const TTS_KINDS = ['http_audio', 'speech_json', 'openai_speech'];

    public static function sttRaw(): string
    {
        return trim(Settings::get('VOICE_CUSTOM_STT_PROVIDERS', ''));
    }

    public static function ttsRaw(): string
    {
        return trim(Settings::get('VOICE_CUSTOM_TTS_PROVIDERS', ''));
    }

    /**
     * @return array<int, array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}>
     */
    public static function sttList(?string $raw = null): array
    {
        return self::parse($raw ?? self::sttRaw(), self::STT_KINDS);
    }

    /**
     * @return array<int, array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}>
     */
    public static function ttsList(?string $raw = null): array
    {
        return self::parse($raw ?? self::ttsRaw(), self::TTS_KINDS);
    }

    /**
     * @return array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}|null
     */
    public static function getSttById(string $id): ?array
    {
        $id = CustomProviders::normalizeSlug($id);
        if ($id === '') {
            return null;
        }
        foreach (self::sttList() as $provider) {
            if ($provider['id'] === $id) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}|null
     */
    public static function getTtsById(string $id): ?array
    {
        $id = CustomProviders::normalizeSlug($id);
        if ($id === '') {
            return null;
        }
        foreach (self::ttsList() as $provider) {
            if ($provider['id'] === $id) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $providers
     */
    public static function encode(array $providers, array $allowedKinds): string
    {
        $validated = [];
        foreach ($providers as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::validateRow($row, $allowedKinds);
            if ($item !== null) {
                $validated[] = $item;
            }
        }

        return $validated === [] ? '[]' : Json::encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, string>
     */
    public static function sttDropdownOptions(): array
    {
        $opts = [];
        foreach (self::sttList() as $provider) {
            $opts[$provider['id']] = $provider['name'];
        }

        return $opts;
    }

    /**
     * @return array<string, string>
     */
    public static function ttsDropdownOptions(): array
    {
        $opts = [];
        foreach (self::ttsList() as $provider) {
            $opts[$provider['id']] = $provider['name'];
        }

        return $opts;
    }

    /**
     * @return array<int, array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}>
     */
    private static function parse(string $raw, array $allowedKinds): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '[]') {
            return [];
        }
        try {
            $data = Json::decode($raw);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::validateRow($row, $allowedKinds);
            if ($item === null || isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, kind: string, url: string, api_key: string, model: string, voice: string, folder_id: string, lang: string, auth: string, extra_headers: array<string, string>}|null
     */
    private static function validateRow(array $row, array $allowedKinds): ?array
    {
        $id = CustomProviders::normalizeSlug((string)($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $kind = strtolower(trim((string)($row['kind'] ?? '')));
        if ($kind === 'openai_speech') {
            $kind = 'speech_json';
        }
        if (!in_array($kind, $allowedKinds, true)) {
            return null;
        }

        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = $id;
        }

        $url = trim((string)($row['url'] ?? ''));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (in_array($kind, ['http', 'http_audio', 'speech_json'], true) && $url === '') {
            return null;
        }

        $auth = strtolower(trim((string)($row['auth'] ?? 'api-key')));
        if (!in_array($auth, ['bearer', 'api-key', 'none'], true)) {
            $auth = 'api-key';
        }

        return [
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'url' => $url,
            'api_key' => trim((string)($row['api_key'] ?? '')),
            'model' => trim((string)($row['model'] ?? '')),
            'voice' => trim((string)($row['voice'] ?? '')),
            'folder_id' => trim((string)($row['folder_id'] ?? '')),
            'lang' => trim((string)($row['lang'] ?? 'ru-RU')) ?: 'ru-RU',
            'auth' => $auth,
            'extra_headers' => self::parseExtraHeaders($row['extra_headers'] ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseExtraHeaders($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            try {
                $raw = Json::decode($raw);
            } catch (\Throwable $e) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            $header = trim((string)$key);
            if ($header === '') {
                continue;
            }
            $out[$header] = trim((string)$value);
        }

        return $out;
    }
}
