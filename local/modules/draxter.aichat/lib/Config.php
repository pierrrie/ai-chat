<?php

namespace Draxter\Aichat;

class Config
{
    public static function get(string $name, string $default = ''): string
    {
        return Settings::get($name, $default);
    }

    public static function isChatLogEnabled(): bool
    {
        if (Settings::get('CHAT_LOG_ENABLED', '') !== '') {
            return Settings::getBool('CHAT_LOG_ENABLED', true);
        }
        if (SiteConfig::isLoaded()) {
            $v = SiteConfig::get('chat.log_enabled', true);
            if (is_bool($v)) {
                return $v;
            }
            $s = strtolower(trim((string)$v));

            return !in_array($s, ['n', 'no', '0', 'false'], true);
        }

        return true;
    }

    public static function isBitrix24LeadEnabled(): bool
    {
        if (Settings::get('BITRIX24_LEAD_ENABLED', '') !== '') {
            return Settings::getBool('BITRIX24_LEAD_ENABLED', true);
        }
        if (SiteConfig::isLoaded()) {
            $v = SiteConfig::get('crm.enabled', true);
            if (is_bool($v)) {
                return $v;
            }
            $s = strtolower(trim((string)$v));

            return !in_array($s, ['n', 'no', '0', 'false'], true);
        }

        return true;
    }

    public static function aiProvider(): string
    {
        $p = strtolower(trim(self::get('AI_PROVIDER', 'gemini')));
        if ($p === 'openai') {
            return 'gemini';
        }
        if (in_array($p, ['deepseek', 'gemini', 'perplexity'], true)) {
            return $p;
        }
        if (CustomProviders::getById($p) !== null) {
            return $p;
        }

        return 'gemini';
    }

    public static function isBuiltInAiProvider(?string $provider = null): bool
    {
        $provider = strtolower(trim($provider ?? self::aiProvider()));

        return in_array($provider, ['gemini', 'deepseek', 'perplexity'], true);
    }

    public static function shopName(): string
    {
        return Settings::shopName();
    }

    public static function brand(): string
    {
        return Settings::brand();
    }

    public static function siteUrl(): string
    {
        $url = Settings::siteUrl();
        if ($url !== '') {
            return $url;
        }
        $info = Catalog::getInfo();
        if (!empty($info['shopUrl'])) {
            return rtrim($info['shopUrl'], '/');
        }

        return 'https://example.local';
    }

    public static function currentModel(): string
    {
        $provider = self::aiProvider();
        if ($provider === 'gemini') {
            return self::get('GEMINI_MODEL', 'gemini-2.5-flash');
        }
        if ($provider === 'deepseek') {
            return self::get('DEEPSEEK_MODEL', 'deepseek-chat');
        }
        if ($provider === 'perplexity') {
            return self::get('PERPLEXITY_MODEL', 'sonar');
        }

        $custom = CustomProviders::getById($provider);
        if ($custom !== null) {
            return $custom['model'];
        }

        return self::get('GEMINI_MODEL', 'gemini-2.5-flash');
    }

    public static function hasLlmApiKey(): bool
    {
        $provider = self::aiProvider();
        if ($provider === 'gemini') {
            return self::get('GEMINI_API_KEY') !== '';
        }
        if ($provider === 'deepseek') {
            return self::get('DEEPSEEK_API_KEY') !== '';
        }
        if ($provider === 'perplexity') {
            return self::get('PERPLEXITY_API_KEY') !== '';
        }

        $custom = CustomProviders::getById($provider);

        return $custom !== null && $custom['api_key'] !== '';
    }

    public static function geminiUseVertex(): bool
    {
        if (self::get('GEMINI_USE_VERTEX', 'N') === 'Y') {
            return true;
        }
        $key = self::get('GEMINI_API_KEY');

        return str_starts_with(trim($key), 'AQ.');
    }
}
