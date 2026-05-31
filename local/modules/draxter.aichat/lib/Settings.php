<?php

namespace Draxter\Aichat;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;

/**
 * Единый слой настроек: Option (b_option, site-aware) → fallback aichat.config.php.
 */
class Settings
{
    public const MODULE = 'draxter.aichat';

    /** @var array<string, array{0: string, 1: string, 2?: string}> */
    public const FLAT_OPTION_MAP = [
        'AI_PROVIDER' => ['ai', 'provider'],
        'GEMINI_API_KEY' => ['ai', 'gemini_api_key'],
        'GEMINI_MODEL' => ['ai', 'gemini_model'],
        'GEMINI_USE_VERTEX' => ['ai', 'gemini_use_vertex', 'bool'],
        'DEEPSEEK_API_KEY' => ['ai', 'deepseek_api_key'],
        'DEEPSEEK_MODEL' => ['ai', 'deepseek_model'],
        'PERPLEXITY_API_KEY' => ['ai', 'perplexity_api_key'],
        'PERPLEXITY_MODEL' => ['ai', 'perplexity_model'],
        'AI_CUSTOM_PROVIDERS' => ['ai', 'custom_providers'],
        'CATALOG_SOURCE' => ['catalog', 'source'],
        'CATALOG_URL' => ['catalog', 'url'],
        'CATALOG_PATH' => ['catalog', 'path'],
        'CATALOG_IBLOCK_ID' => ['catalog', 'iblock_id', 'int'],
        'CATALOG_REFRESH_MINUTES' => ['catalog', 'refresh_minutes', 'int'],
        'CATALOG_PROFILE' => ['catalog', 'profile'],
        'SHOP_NAME' => ['shop', 'name'],
        'SHOP_BRAND' => ['shop', 'brand'],
        'SITE_URL' => ['shop', 'site_url'],
        'SHOP_SPECIALIZATION' => ['shop', 'specialization'],
        'CHAT_LOG_ENABLED' => ['chat', 'log_enabled', 'bool'],
        'CHAT_LOG_DIR' => ['chat', 'log_dir'],
        'CHAT_WELCOME' => ['chat', 'welcome'],
        'ENRICH_LINKS_ENABLED' => ['chat', 'enrich_links_enabled', 'bool'],
        'ENRICH_LINKS_ON_REQUEST' => ['chat', 'enrich_links_on_request', 'bool'],
        'ENRICH_LINKS_ON_MENTION' => ['chat', 'enrich_links_on_mention', 'bool'],
        'ENRICH_LINKS_ON_SHOPPING' => ['chat', 'enrich_links_on_shopping', 'bool'],
        'ENRICH_LINKS_ON_CONTEXT' => ['chat', 'enrich_links_on_context', 'bool'],
        'ENRICH_LINKS_SKIP_PHRASE' => ['chat', 'enrich_links_skip_phrase', 'bool'],
        'ENRICH_LINKS_TITLE' => ['chat', 'enrich_links_title'],
        'ENRICH_LINKS_MAX' => ['chat', 'enrich_links_max', 'int'],
        'BITRIX24_LEAD_ENABLED' => ['crm', 'enabled', 'bool'],
        'BITRIX24_WEBHOOK_URL' => ['crm', 'webhook_url'],
        'BITRIX24_ASSIGNED_ID' => ['crm', 'assigned_id', 'int'],
        'BITRIX24_SOURCE_ID' => ['crm', 'source_id'],
        'BITRIX24_SOURCE_LABEL' => ['crm', 'source_label'],
        'CRM_LEAD_TITLE_PREFIX' => ['crm', 'lead_title_prefix'],
        'YANDEX_METRIKA_ENABLED' => ['crm', 'metrika_enabled', 'bool'],
        'YANDEX_METRIKA_COUNTER_ID' => ['crm', 'metrika_counter_id'],
        'YANDEX_METRIKA_GOAL' => ['crm', 'metrika_goal'],
        'CURL_SSL_VERIFY' => ['curl', 'ssl_verify', 'bool'],
        'WIDGET_ENABLED' => ['widget', 'enabled', 'bool'],
        'AVATAR_FILE_ID' => ['widget', 'avatar_file_id', 'int'],
        'WIDGET_ACCENT_COLOR' => ['widget', 'accent_color'],
        'WIDGET_FAB_COLOR' => ['widget', 'fab_color'],
        'WIDGET_FAB_TITLE' => ['widget', 'fab_title'],
        'WIDGET_FAB_SUBTITLE' => ['widget', 'fab_subtitle'],
        'WIDGET_FAB_ALIGN_H' => ['widget', 'fab_align_h'],
        'WIDGET_FAB_ALIGN_V' => ['widget', 'fab_align_v'],
        'WIDGET_FAB_RIGHT' => ['widget', 'fab_right'],
        'WIDGET_FAB_BOTTOM' => ['widget', 'fab_bottom'],
        'WIDGET_AGENT_NAME' => ['widget', 'agent_name'],
        'WIDGET_STATUS_TEXT' => ['widget', 'status_text'],
        'WIDGET_SHOW_ONLINE' => ['widget', 'show_online', 'bool'],
        'VOICE_ENABLED' => ['voice', 'enabled', 'bool'],
        'VOICE_REPLY_ENABLED' => ['voice', 'reply_enabled', 'bool'],
        'VOICE_STT_PROVIDER' => ['voice', 'stt_provider'],
        'VOICE_GEMINI_STT_MODEL' => ['voice', 'gemini_stt_model'],
        'VOICE_WHISPER_API_KEY' => ['voice', 'whisper_api_key'],
        'VOICE_WHISPER_BASE_URL' => ['voice', 'whisper_base_url'],
        'VOICE_WHISPER_MODEL' => ['voice', 'whisper_model'],
        'VOICE_CUSTOM_STT_PROVIDERS' => ['voice', 'custom_stt_providers'],
        'VOICE_CUSTOM_TTS_PROVIDERS' => ['voice', 'custom_tts_providers'],
        'VOICE_TTS_PROVIDER' => ['voice', 'tts_provider'],
        'CRM_B24_FIELD_MAP' => ['crm', 'b24_field_map'],
        'CRM_LOCAL_FIELD_MAP' => ['crm', 'local_field_map'],
        'CRM_LOCAL_ENABLED' => ['crm', 'local_enabled', 'bool'],
        'CRM_EMAIL_ENABLED' => ['crm', 'email_enabled', 'bool'],
        'CRM_EMAIL_TO' => ['crm', 'email_to'],
        'VOICE_MAX_SECONDS' => ['voice', 'max_seconds', 'int'],
        'VOICE_TTS_MAX_CHARS' => ['voice', 'tts_max_chars', 'int'],
        'VOICE_TTS_RATE' => ['voice', 'tts_rate'],
        'RATE_LIMIT_PER_MINUTE' => ['security', 'rate_limit_per_minute', 'int'],
        'WIDGET_AUTO_INJECT' => ['widget', 'auto_inject', 'bool'],
    ];

    /** @var array<string, string> dot path => option name */
    private const DOT_TO_OPTION = [
        'prompts.role_line' => 'PROMPT_ROLE_LINE',
        'prompts.small_talk_hint' => 'PROMPT_SMALL_TALK_HINT',
        'prompts.search_rules' => 'PROMPT_SEARCH_RULES',
        'prompts.catalog_synonyms_line' => 'PROMPT_CATALOG_SYNONYMS',
        'prompts.style_brief' => 'PROMPT_STYLE_BRIEF',
        'prompts.style_detailed' => 'PROMPT_STYLE_DETAILED',
        'prompts.rules_common' => 'PROMPT_RULES_COMMON',
        'prompts.catalog_header' => 'PROMPT_CATALOG_HEADER',
        'prompts.link_rules' => 'PROMPT_LINK_RULES',
        'prompts.domain_rules' => 'PROMPT_DOMAIN_RULES',
        'prompts.faq_intro' => 'PROMPT_FAQ_INTRO',
        'prompts.faq_knowledge' => 'PROMPT_FAQ_KNOWLEDGE',
        'prompts.custom_blocks' => 'PROMPT_CUSTOM_BLOCKS',
        'intent.small_talk_regex' => 'INTENT_SMALL_TALK_REGEX',
        'intent.product_keywords_regex' => 'INTENT_PRODUCT_KEYWORDS_REGEX',
        'intent.purchase_intent_regex' => 'INTENT_PURCHASE_INTENT_REGEX',
        'intent.shopping_intent_regex' => 'INTENT_SHOPPING_INTENT_REGEX',
        'intent.topic_words' => 'INTENT_TOPIC_WORDS',
        'intent.motorcycle_query_regex' => 'INTENT_MOTORCYCLE_QUERY_REGEX',
        'intent.motorcycle_append_keyword' => 'INTENT_MOTORCYCLE_APPEND_KEYWORD',
        'messages.lead_phone_cta' => 'MSG_LEAD_PHONE_CTA',
        'messages.lead_phone_cta_prompt' => 'MSG_LEAD_PHONE_CTA_PROMPT',
        'messages.phone_reply_ok' => 'MSG_PHONE_REPLY_OK',
        'messages.phone_reply_fallback' => 'MSG_PHONE_REPLY_FALLBACK',
    ];

    private static ?string $resolvedSiteId = null;

    /** @var array<string, string>|null */
    private static ?array $moduleDefaults = null;

    public static function resolveSiteId(): string
    {
        if (self::$resolvedSiteId !== null) {
            return self::$resolvedSiteId;
        }

        if (defined('SITE_ID') && is_string(SITE_ID) && SITE_ID !== '') {
            return self::$resolvedSiteId = SITE_ID;
        }

        try {
            $site = Context::getCurrent()->getSite();
            if ($site && method_exists($site, 'getId')) {
                $id = (string)$site->getId();
                if ($id !== '') {
                    return self::$resolvedSiteId = $id;
                }
            }
        } catch (\Throwable $e) {
        }

        return self::$resolvedSiteId = '';
    }

    public static function resetSiteCache(): void
    {
        self::$resolvedSiteId = null;
    }

    public static function get(string $name, string $default = '', ?string $siteId = null): string
    {
        $siteId = $siteId ?? self::resolveSiteId();
        $fromOption = (string)Option::get(self::MODULE, $name, '', $siteId);
        if ($fromOption !== '') {
            return $fromOption;
        }

        $fromFile = self::fileValueForFlatOption($name);
        if ($fromFile !== null && $fromFile !== '') {
            return $fromFile;
        }

        $fromModuleDefault = self::moduleDefaultValue($name);
        if ($fromModuleDefault !== null && $fromModuleDefault !== '') {
            return $fromModuleDefault;
        }

        return $default;
    }

    public static function set(string $name, string $value, ?string $siteId = null): void
    {
        $siteId = $siteId ?? self::resolveSiteId();
        Option::set(self::MODULE, $name, $value, $siteId);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getDot(string $dotPath, $default = null, ?string $siteId = null)
    {
        if (isset(self::DOT_TO_OPTION[$dotPath])) {
            $opt = self::get(self::DOT_TO_OPTION[$dotPath], '', $siteId);
            if ($opt !== '') {
                if ($dotPath === 'prompts.domain_rules') {
                    return self::parseDomainRules($opt);
                }
                if ($dotPath === 'intent.topic_words') {
                    return self::parseTopicWords($opt);
                }

                return $opt;
            }
        }

        if (isset(self::FLAT_OPTION_MAP[$dotPath])) {
            return self::get($dotPath, is_string($default) ? $default : '', $siteId);
        }

        foreach (self::FLAT_OPTION_MAP as $optName => $map) {
            if ($map[0] . '.' . $map[1] === $dotPath) {
                $v = self::get($optName, '', $siteId);
                if ($v !== '') {
                    return self::castFileValue($v, $map[2] ?? 'string');
                }
            }
        }

        $fileVal = SiteConfig::get($dotPath, null);
        if ($fileVal !== null && $fileVal !== '') {
            return $fileVal;
        }

        return $default;
    }

    public static function getString(string $dotPath, string $default = '', ?string $siteId = null): string
    {
        $v = self::getDot($dotPath, $default, $siteId);

        return trim((string)$v);
    }

    public static function getBool(string $dotPathOrOption, bool $default = false, ?string $siteId = null): bool
    {
        if (isset(self::FLAT_OPTION_MAP[$dotPathOrOption])) {
            $v = strtolower(trim(self::get($dotPathOrOption, '', $siteId)));
            if ($v === '') {
                $file = self::fileValueForFlatOption($dotPathOrOption);
                if ($file !== null && $file !== '') {
                    $v = strtolower($file);
                } else {
                    $moduleDef = self::moduleDefaultValue($dotPathOrOption);
                    if ($moduleDef !== null && $moduleDef !== '') {
                        $v = strtolower($moduleDef);
                    } else {
                        return $default;
                    }
                }
            }

            return in_array($v, ['y', 'yes', '1', 'true'], true);
        }

        $v = self::getDot($dotPathOrOption, null, $siteId);
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));

        return in_array($s, ['y', 'yes', '1', 'true'], true);
    }

    public static function getInt(string $name, int $default = 0, ?string $siteId = null): int
    {
        $v = trim(self::get($name, '', $siteId));
        if ($v === '') {
            return $default;
        }

        return (int)$v;
    }

    public static function shopName(): string
    {
        $name = trim(self::get('SHOP_NAME', ''));
        if ($name !== '') {
            return $name;
        }
        $fromFile = SiteConfig::shopName();
        if ($fromFile !== '') {
            return $fromFile;
        }
        $info = Catalog::getInfo();

        return $info['shopName'] ?? 'Магазин';
    }

    public static function brand(): string
    {
        $brand = trim(self::get('SHOP_BRAND', ''));
        if ($brand !== '') {
            return $brand;
        }
        if (SiteConfig::isLoaded()) {
            return SiteConfig::brand();
        }

        return self::shopName();
    }

    public static function siteUrl(): string
    {
        $url = trim(self::get('SITE_URL', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        $fromFile = SiteConfig::siteUrl();
        if ($fromFile !== '') {
            return $fromFile;
        }
        $info = Catalog::getInfo();
        if (!empty($info['shopUrl'])) {
            return rtrim($info['shopUrl'], '/');
        }
        try {
            $request = Context::getCurrent()->getRequest();
            $scheme = $request->isHttps() ? 'https' : 'http';
            $host = $request->getHttpHost();
            if ($host !== '') {
                return $scheme . '://' . $host;
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    public const LEAD_CREATED_STREAM_MARKER = '<!--draxter-aichat-lead:1-->';

    public static function bitrix24SourceId(): string
    {
        $id = trim(self::get('BITRIX24_SOURCE_ID', ''));
        if ($id !== '') {
            return $id;
        }
        $fromFile = trim((string)SiteConfig::get('crm.source_id', ''));
        if ($fromFile !== '') {
            return $fromFile;
        }

        return 'aichat';
    }

    /** Подпись источника в UF «Источник» и SOURCE_DESCRIPTION (не домен сайта). */
    public static function bitrix24SourceLabel(): string
    {
        $label = trim(self::get('BITRIX24_SOURCE_LABEL', ''));
        if ($label !== '') {
            return $label;
        }
        $fromFile = trim((string)SiteConfig::get('crm.source_label', ''));
        if ($fromFile !== '') {
            return $fromFile;
        }

        return 'aichat';
    }

    public static function isYandexMetrikaEnabled(): bool
    {
        return self::getBool('YANDEX_METRIKA_ENABLED', true);
    }

    public static function yandexMetrikaCounterId(): int
    {
        $raw = trim(self::get('YANDEX_METRIKA_COUNTER_ID', ''));
        if ($raw === '') {
            $raw = trim((string)SiteConfig::get('crm.metrika_counter_id', '90547846'));
        }
        $id = (int)$raw;

        return $id > 0 ? $id : 90547846;
    }

    public static function yandexMetrikaGoal(): string
    {
        $goal = trim(self::get('YANDEX_METRIKA_GOAL', ''));
        if ($goal !== '') {
            return $goal;
        }
        $fromFile = trim((string)SiteConfig::get('crm.metrika_goal', ''));
        if ($fromFile !== '') {
            return $fromFile;
        }

        return 'aibot';
    }

    public static function leadCreatedStreamMarker(): string
    {
        return self::LEAD_CREATED_STREAM_MARKER;
    }

    public static function widgetAgentName(): string
    {
        $name = trim(self::get('WIDGET_AGENT_NAME', ''));
        if ($name !== '') {
            return $name;
        }

        return self::shopName();
    }

    public static function widgetStatusText(): string
    {
        $text = trim(self::get('WIDGET_STATUS_TEXT', ''));
        if ($text !== '') {
            return $text;
        }
        $legacy = trim(self::get('WIDGET_STATUS_LINE', ''));
        if ($legacy !== '') {
            return $legacy;
        }

        return 'Онлайн • отвечает сразу';
    }

    public static function showOnlineStatus(): bool
    {
        if (self::get('WIDGET_SHOW_ONLINE', '') !== '') {
            return self::getBool('WIDGET_SHOW_ONLINE', true);
        }

        return true;
    }

    /** @deprecated use widgetStatusText() */
    public static function widgetStatusLine(): string
    {
        return self::widgetStatusText();
    }

    public static function welcomeMessage(): string
    {
        $w = trim(self::get('CHAT_WELCOME', ''));
        if ($w !== '') {
            return $w;
        }
        if (SiteConfig::isLoaded()) {
            return SiteConfig::welcomeMessage();
        }

        return 'Здравствуйте! Помогу подобрать товар, сравнить модели и ответить по ценам и наличию. Чем могу помочь?';
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
        $template = self::getString('messages.' . $key, '');
        if ($template === '' && SiteConfig::isLoaded()) {
            $template = SiteConfig::message($key, $vars);
            if ($template !== '') {
                return $template;
            }
        }
        if ($template === '') {
            return '';
        }

        return self::format($template, $vars);
    }

    /**
     * @return string[]
     */
    public static function domainRules(): array
    {
        $opt = trim(self::get('PROMPT_DOMAIN_RULES', ''));
        if ($opt !== '') {
            return self::parseDomainRules($opt);
        }
        if (SiteConfig::isLoaded()) {
            return SiteConfig::domainRules();
        }

        return [];
    }

    public static function faqKnowledgeRaw(): string
    {
        $opt = trim(self::get('PROMPT_FAQ_KNOWLEDGE', ''));
        if ($opt !== '') {
            return $opt;
        }
        $fromFile = SiteConfig::get('prompts.faq_knowledge', '');
        if (is_string($fromFile) && trim($fromFile) !== '') {
            return trim($fromFile);
        }

        return '';
    }

    public static function regex(string $intentKey, string $fallback): string
    {
        $map = [
            'small_talk_regex' => 'INTENT_SMALL_TALK_REGEX',
            'product_keywords_regex' => 'INTENT_PRODUCT_KEYWORDS_REGEX',
            'purchase_intent_regex' => 'INTENT_PURCHASE_INTENT_REGEX',
            'shopping_intent_regex' => 'INTENT_SHOPPING_INTENT_REGEX',
            'motorcycle_query_regex' => 'INTENT_MOTORCYCLE_QUERY_REGEX',
        ];
        if (isset($map[$intentKey])) {
            $v = trim(self::get($map[$intentKey], ''));
            if ($v !== '') {
                return $v;
            }
        }

        return SiteConfig::regex($intentKey, $fallback);
    }

    /**
     * @return string[]
     */
    public static function topicWords(): array
    {
        $opt = trim(self::get('INTENT_TOPIC_WORDS', ''));
        if ($opt !== '') {
            return self::parseTopicWords($opt);
        }

        return SiteConfig::topicWords();
    }

    public static function defaultAvatarUrl(): string
    {
        return '/local/components/draxter/aichat.chat/templates/.default/images/default-avatar.png';
    }

    public static function customAvatarUrl(): string
    {
        $fileId = self::getInt('AVATAR_FILE_ID', 0);
        if ($fileId <= 0) {
            return '';
        }
        if (!class_exists('\CFile')) {
            return '';
        }
        $path = \CFile::GetPath($fileId);
        if (!is_string($path) || $path === '') {
            return '';
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docRoot !== '' && is_file($docRoot . $path)) {
            return $path;
        }

        return $path;
    }

    public static function avatarUrl(): string
    {
        $custom = self::customAvatarUrl();
        if ($custom !== '') {
            return $custom;
        }

        return self::defaultAvatarUrl();
    }

    public static function accentColor(): string
    {
        $c = trim(self::get('WIDGET_ACCENT_COLOR', '#2563eb'));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) {
            return $c;
        }

        return '#2563eb';
    }

    public static function fabButtonColor(): string
    {
        $c = trim(self::get('WIDGET_FAB_COLOR', ''));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) {
            return $c;
        }

        return self::accentColor();
    }

    public static function fabTitle(): string
    {
        $title = trim(self::get('WIDGET_FAB_TITLE', ''));
        if ($title !== '') {
            return $title;
        }

        return 'Чат';
    }

    public static function fabSubtitle(): string
    {
        $subtitle = trim(self::get('WIDGET_FAB_SUBTITLE', ''));
        if ($subtitle !== '') {
            return $subtitle;
        }

        return 'Спросить о товаре';
    }

    /** @return 'left'|'right' */
    public static function fabHorizontalAlign(): string
    {
        $side = strtolower(trim(self::get('WIDGET_FAB_ALIGN_H', 'right')));

        return $side === 'left' ? 'left' : 'right';
    }

    /** @return 'top'|'bottom' */
    public static function fabVerticalAlign(): string
    {
        $side = strtolower(trim(self::get('WIDGET_FAB_ALIGN_V', 'bottom')));

        return $side === 'top' ? 'top' : 'bottom';
    }

    public static function fabHorizontalOffset(): int
    {
        $n = self::getInt('WIDGET_FAB_RIGHT', 24);

        return max(0, min(400, $n));
    }

    public static function fabVerticalOffset(): int
    {
        $n = self::getInt('WIDGET_FAB_BOTTOM', 24);

        return max(0, min(400, $n));
    }

    public static function isWidgetEnabled(): bool
    {
        return self::getBool('WIDGET_ENABLED', true);
    }

    public static function isVoiceEnabled(): bool
    {
        return self::getBool('VOICE_ENABLED', true);
    }

    public static function isVoiceReplyEnabled(): bool
    {
        return self::getBool('VOICE_REPLY_ENABLED', false);
    }

    public static function voiceSttProvider(): string
    {
        $p = strtolower(trim(self::get('VOICE_STT_PROVIDER', 'auto')));
        if (in_array($p, ['openai', 'openai_whisper', 'yandex'], true)) {
            return 'auto';
        }
        if (in_array($p, ['auto', 'browser', 'gemini'], true)) {
            return $p;
        }
        if (CustomVoiceProviders::getSttById($p) !== null) {
            return $p;
        }

        return 'auto';
    }

    /**
     * Фактический STT: browser | gemini | custom:{id}
     *
     * @throws \RuntimeException если выбран провайдер без ключей
     */
    public static function resolveVoiceSttProvider(): string
    {
        $pref = self::voiceSttProvider();
        if ($pref === 'browser') {
            return 'browser';
        }
        if ($pref === 'gemini') {
            if (trim(Config::get('GEMINI_API_KEY', '')) === '') {
                throw new \RuntimeException('Нужен GEMINI_API_KEY для распознавания речи (Gemini STT)');
            }

            return 'gemini';
        }
        if ($pref !== 'auto' && CustomVoiceProviders::getSttById($pref) !== null) {
            return 'custom:' . $pref;
        }

        if (trim(Config::get('GEMINI_API_KEY', '')) !== '') {
            return 'gemini';
        }

        return 'browser';
    }

    public static function voiceWhisperBaseUrl(): string
    {
        $url = rtrim(trim(self::get('VOICE_WHISPER_BASE_URL', 'https://api.openai.com/v1')), '/');

        return $url !== '' ? $url : 'https://api.openai.com/v1';
    }

    public static function voiceWhisperModel(): string
    {
        $model = trim(self::get('VOICE_WHISPER_MODEL', 'whisper-1'));

        return $model !== '' ? $model : 'whisper-1';
    }

    public static function voiceMaxSeconds(): int
    {
        $n = self::getInt('VOICE_MAX_SECONDS', 60);

        return max(5, min(120, $n));
    }

    public static function voiceTtsMaxChars(): int
    {
        $n = self::getInt('VOICE_TTS_MAX_CHARS', 1500);

        return max(100, min(4000, $n));
    }

    public static function voiceTtsRate(): float
    {
        $raw = str_replace(',', '.', trim(self::get('VOICE_TTS_RATE', '1.25')));
        $rate = is_numeric($raw) ? (float)$raw : 1.25;

        return max(0.5, min(2.0, $rate));
    }

    public static function voiceTtsProvider(): string
    {
        $p = strtolower(trim(self::get('VOICE_TTS_PROVIDER', 'browser')));
        if ($p === 'yandex') {
            return 'browser';
        }
        if ($p === 'browser') {
            return 'browser';
        }
        if (CustomVoiceProviders::getTtsById($p) !== null) {
            return $p;
        }

        return 'browser';
    }

    /**
     * @throws \RuntimeException если выбран провайдер без ключей
     */
    public static function resolveVoiceTtsProvider(): string
    {
        $pref = self::voiceTtsProvider();
        if ($pref === 'browser') {
            return 'browser';
        }
        if (CustomVoiceProviders::getTtsById($pref) !== null) {
            return 'custom:' . $pref;
        }

        return 'browser';
    }

    public static function isCrmLocalEnabled(): bool
    {
        return self::getBool('CRM_LOCAL_ENABLED', false);
    }

    public static function isCrmEmailEnabled(): bool
    {
        return self::getBool('CRM_EMAIL_ENABLED', false);
    }

    /**
     * @return string[]
     */
    public static function crmEmailRecipients(): array
    {
        $raw = trim(self::get('CRM_EMAIL_TO', ''));
        if ($raw === '') {
            return [];
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[,;\n]+/u', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn($e) => $e !== '' && strpos($e, '@') !== false));
    }

    public static function rateLimitPerMinute(): int
    {
        $n = self::getInt('RATE_LIMIT_PER_MINUTE', 60);

        return max(0, min(300, $n));
    }

    public static function sslVerify(): bool
    {
        if (self::get('CURL_SSL_VERIFY', '') !== '') {
            return self::getBool('CURL_SSL_VERIFY', true);
        }
        $file = SiteConfig::get('curl.ssl_verify', null);
        if ($file === null) {
            return true;
        }
        if (is_bool($file)) {
            return $file;
        }

        return !in_array(strtolower(trim((string)$file)), ['n', 'no', '0', 'false'], true);
    }

    /**
     * @return array<string, string>
     */
    public static function exportFlatOptions(): array
    {
        $out = [];
        foreach (array_keys(self::FLAT_OPTION_MAP) as $name) {
            $v = SiteConfig::optionValue($name);
            if ($v !== null && $v !== '') {
                $out[$name] = $v;
            }
        }
        foreach (self::DOT_TO_OPTION as $dot => $optName) {
            $v = SiteConfig::get($dot, null);
            if ($v === null || $v === '') {
                continue;
            }
            if ($dot === 'prompts.domain_rules' && is_array($v)) {
                $out[$optName] = implode("\n", array_map('strval', $v));
            } elseif ($dot === 'intent.topic_words' && is_array($v)) {
                $out[$optName] = implode(',', array_map('strval', $v));
            } else {
                $out[$optName] = trim((string)$v);
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public static function allOptionNames(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::FLAT_OPTION_MAP),
            array_values(self::DOT_TO_OPTION)
        )));
    }

    private static function moduleDefaultValue(string $name): ?string
    {
        if (self::$moduleDefaults === null) {
            self::$moduleDefaults = [];
            global $draxter_aichat_default_option;
            $path = dirname(__DIR__) . '/default_options.php';
            if (is_readable($path)) {
                include $path;
                if (isset($draxter_aichat_default_option) && is_array($draxter_aichat_default_option)) {
                    foreach ($draxter_aichat_default_option as $k => $v) {
                        self::$moduleDefaults[(string)$k] = (string)$v;
                    }
                }
            }
        }

        return self::$moduleDefaults[$name] ?? null;
    }

    private static function fileValueForFlatOption(string $name): ?string
    {
        if (!isset(self::FLAT_OPTION_MAP[$name])) {
            return SiteConfig::optionValue($name);
        }
        if (!SiteConfig::isLoaded()) {
            return null;
        }

        [$section, $key, $type] = self::FLAT_OPTION_MAP[$name] + [2 => 'string'];
        $value = SiteConfig::get($section . '.' . $key, null);
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
     * @param mixed $value
     * @return mixed
     */
    private static function castFileValue(string $value, string $type)
    {
        if ($type === 'bool') {
            return in_array(strtolower($value), ['y', 'yes', '1', 'true'], true);
        }
        if ($type === 'int') {
            return (int)$value;
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private static function parseDomainRules(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
    }

    /**
     * @return string[]
     */
    private static function parseTopicWords(string $text): array
    {
        $parts = preg_split('/[,;\n]+/u', $text) ?: [];

        return array_values(array_filter(array_map(static fn($w) => trim($w), $parts), static fn($w) => $w !== ''));
    }
}
