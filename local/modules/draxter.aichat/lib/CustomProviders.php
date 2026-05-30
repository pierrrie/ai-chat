<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class CustomProviders
{
    public const BUILT_IN = ['gemini', 'deepseek', 'perplexity'];

    public static function raw(): string
    {
        return trim(Settings::get('AI_CUSTOM_PROVIDERS', ''));
    }

    /**
     * @return array<int, array{id: string, name: string, url: string, api_key: string, model: string, auth: string, extra_headers: array<string, string>}>
     */
    public static function list(?string $raw = null): array
    {
        return self::parse($raw ?? self::raw());
    }

    /**
     * @return array{id: string, name: string, url: string, api_key: string, model: string, auth: string, extra_headers: array<string, string>}|null
     */
    public static function getById(string $id): ?array
    {
        $id = self::normalizeSlug($id);
        if ($id === '') {
            return null;
        }
        foreach (self::list() as $provider) {
            if ($provider['id'] === $id) {
                return $provider;
            }
        }

        return null;
    }

    public static function isBuiltIn(string $id): bool
    {
        return in_array(strtolower(trim($id)), self::BUILT_IN, true);
    }

    public static function isReservedSlug(string $id): bool
    {
        $id = strtolower(trim($id));

        return $id === '' || $id === 'openai' || self::isBuiltIn($id);
    }

    /**
     * @param array<int, array<string, mixed>> $providers
     */
    public static function encode(array $providers): string
    {
        $validated = [];
        foreach ($providers as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::validateRow($row);
            if ($item !== null) {
                $validated[] = $item;
            }
        }

        if ($validated === []) {
            return '[]';
        }

        return Json::encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, array{id: string, name: string, url: string, api_key: string, model: string, auth: string, extra_headers: array<string, string>}>
     */
    public static function parse(string $raw): array
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
            $item = self::validateRow($row);
            if ($item === null || isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function dropdownOptions(): array
    {
        $opts = [];
        foreach (self::list() as $provider) {
            $opts[$provider['id']] = $provider['name'];
        }

        return $opts;
    }

    public static function displayName(string $providerId): string
    {
        if ($providerId === 'gemini') {
            return 'Gemini';
        }
        if ($providerId === 'deepseek') {
            return 'DeepSeek';
        }
        if ($providerId === 'perplexity') {
            return 'Perplexity';
        }
        $custom = self::getById($providerId);
        if ($custom !== null) {
            return $custom['name'];
        }

        return $providerId;
    }

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-_');

        return $slug;
    }

    /**
     * Предупреждения по адресам API при сохранении (не блокирует сохранение).
     *
     * @param array<int, array<string, mixed>> $providers
     * @return array<int, string>
     */
    public static function collectUrlWarnings(array $providers): array
    {
        $warnings = [];
        foreach ($providers as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $label = trim((string)($row['name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($row['id'] ?? ''));
            }
            if ($label === '') {
                $label = 'Провайдер ' . ($index + 1);
            }

            if (!preg_match('#^https://#i', $url)) {
                $warnings[] = '«' . $label . '»: адрес API должен начинаться с https:// (сейчас: ' . mb_substr($url, 0, 120) . ').';
                continue;
            }

            $lower = strtolower($url);
            if (str_contains($lower, '/bitrix/admin')
                || str_contains($lower, '/bitrix/tools')
                || preg_match('#(^|/)(admin|options)\.php(\?|$)#i', $lower)
            ) {
                $warnings[] = '«' . $label . '»: похоже на адрес панели Bitrix, а не API нейросети. Укажите полный URL из документации сервиса (обычно …/v1/chat/completions).';
                continue;
            }

            if (!str_contains($url, '.') || strlen($url) < 20) {
                $warnings[] = '«' . $label . '»: адрес слишком короткий или без домена — вставьте полную ссылку из личного кабинета провайдера.';
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, url: string, api_key: string, model: string, auth: string, extra_headers: array<string, string>}|null
     */
    private static function validateRow(array $row): ?array
    {
        $id = self::normalizeSlug((string)($row['id'] ?? ''));
        if ($id === '' || self::isReservedSlug($id)) {
            return null;
        }

        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = $id;
        }

        $url = trim((string)($row['url'] ?? ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $model = trim((string)($row['model'] ?? ''));
        if ($model === '') {
            return null;
        }

        $auth = strtolower(trim((string)($row['auth'] ?? 'bearer')));
        if (!in_array($auth, ['bearer', 'api-key', 'none'], true)) {
            $auth = 'bearer';
        }

        return [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'api_key' => trim((string)($row['api_key'] ?? '')),
            'model' => $model,
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
