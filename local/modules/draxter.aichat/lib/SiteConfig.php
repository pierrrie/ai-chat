<?php

namespace Draxter\Aichat;

class SiteConfig
{
    private static ?array $data = null;
    private static ?string $path = null;

    /** @return array<string, array{0: string, 1: string, 2?: string}> */
    private static function optionMap(): array
    {
        return Settings::FLAT_OPTION_MAP;
    }

    /**
     * @return string[]
     */
    private static function configPathCandidates(): array
    {
        if (defined('DRAXTER_AICHAT_CONFIG') && is_string(DRAXTER_AICHAT_CONFIG) && DRAXTER_AICHAT_CONFIG !== '') {
            return [DRAXTER_AICHAT_CONFIG];
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        $siteRoot = dirname(__DIR__, 4);
        $candidates = [];
        if ($docRoot !== '') {
            $candidates[] = $docRoot . '/config/aichat.config.php';
            $candidates[] = $docRoot . '/../config/aichat.config.php';
            $candidates[] = $docRoot . '/local/config/aichat.config.php';
        }
        $candidates[] = $siteRoot . '/config/aichat.config.php';

        $unique = [];
        foreach ($candidates as $path) {
            $real = realpath($path);
            $key = $real !== false ? $real : $path;
            if (!isset($unique[$key])) {
                $unique[$key] = $real !== false ? $real : $path;
            }
        }

        return array_values($unique);
    }

    public static function configPath(): string
    {
        $candidates = self::configPathCandidates();
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return $candidates[0] ?? dirname(__DIR__, 4) . '/config/aichat.config.php';
    }

    public static function isLoaded(): bool
    {
        self::load();
        return self::$data !== [];
    }

    public static function loadedPath(): ?string
    {
        self::load();
        return self::$path;
    }

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        self::$path = null;
        foreach (self::configPathCandidates() as $path) {
            if (is_readable($path)) {
                self::$path = $path;
                break;
            }
        }
        if (self::$path === null) {
            self::$data = [];
            return self::$data;
        }

        $data = include self::$path;
        self::$data = is_array($data) ? $data : [];

        return self::$data;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $dotPath, $default = null)
    {
        $data = self::load();
        $parts = explode('.', $dotPath);
        $cur = $data;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    public static function shopName(): string
    {
        return trim((string)self::get('shop.name', ''));
    }

    public static function brand(): string
    {
        $brand = trim((string)self::get('shop.brand', ''));
        return $brand !== '' ? $brand : self::shopName();
    }

    public static function siteUrl(): string
    {
        return rtrim(trim((string)self::get('shop.site_url', '')), '/');
    }

    public static function welcomeMessage(): string
    {
        $w = trim((string)self::get('chat.welcome', ''));
        return $w !== '' ? $w : 'Здравствуйте! Чем могу помочь с выбором товара?';
    }

    /**
     * @param array<string, string> $vars
     */
    public static function format(string $template, array $vars = []): string
    {
        $vars = array_merge([
            'shop_name' => self::shopName(),
            'brand' => self::brand(),
        ], $vars);

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use ($vars): string {
                return $vars[$m[1]] ?? $m[0];
            },
            $template
        );
    }

    public static function message(string $key, array $vars = []): string
    {
        $template = (string)self::get('messages.' . $key, '');
        return $template !== '' ? self::format($template, $vars) : '';
    }

    /**
     * @return string[]
     */
    public static function domainRules(): array
    {
        $rules = self::get('prompts.domain_rules', []);
        return is_array($rules) ? array_values(array_filter(array_map('strval', $rules))) : [];
    }

    public static function regex(string $intentKey, string $fallback): string
    {
        $v = trim((string)self::get('intent.' . $intentKey, ''));
        return $v !== '' ? $v : $fallback;
    }

    /**
     * @return string[]
     */
    public static function topicWords(): array
    {
        $words = self::get('intent.topic_words', []);
        return is_array($words) ? array_values(array_filter(array_map('strval', $words))) : [];
    }

    public static function optionValue(string $bitrixOptionName): ?string
    {
        $map = self::optionMap();
        if (!isset($map[$bitrixOptionName])) {
            return null;
        }

        [$section, $key, $type] = $map[$bitrixOptionName] + [2 => 'string'];
        if (!self::isLoaded()) {
            return null;
        }

        $value = self::get($section . '.' . $key, null);
        if ($value === null || $value === '') {
            return null;
        }

        if ($type === 'bool') {
            if (is_bool($value)) {
                return $value ? 'Y' : 'N';
            }
            $s = strtolower(trim((string)$value));
            return in_array($s, ['1', 'y', 'yes', 'true'], true) ? 'Y' : 'N';
        }

        if ($type === 'int') {
            return (string)(int)$value;
        }

        return trim((string)$value);
    }

    /**
     * Экспорт плоского массива для синхронизации с настройками модуля (админка).
     *
     * @return array<string, string>
     */
    public static function exportFlatOptions(): array
    {
        return Settings::exportFlatOptions();
    }
}
