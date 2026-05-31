<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Draxter\Aichat\AdminStats;
use Draxter\Aichat\ApiErrorLog;
use Draxter\Aichat\Bitrix24Lead;
use Draxter\Aichat\Catalog;
use Draxter\Aichat\ChatLog;
use Draxter\Aichat\CrmFieldMapper;
use Draxter\Aichat\CustomProviders;
use Draxter\Aichat\CustomVoiceProviders;
use Draxter\Aichat\FaqKnowledge;
use Draxter\Aichat\Settings;
use Draxter\Aichat\SiteConfig;

$moduleId = 'draxter.aichat';
Loader::includeModule($moduleId);
Loc::loadMessages(__FILE__);

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$configPath = SiteConfig::configPath();
$configLoaded = SiteConfig::isLoaded();
$siteId = (string)($_REQUEST['site_id'] ?? '');

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('draxter_faq_preview') === 'Y') {
    header('Content-Type: application/json; charset=utf-8');
    $question = trim((string)$request->getPost('question'));
    if ($question === '') {
        echo json_encode(['error' => 'Введите тестовый вопрос'], JSON_UNESCAPED_UNICODE);
        die();
    }
    $withAi = $request->getPost('with_ai') === 'Y';
    $faqKnowledge = $request->getPost('faq_knowledge');
    $faqIntro = $request->getPost('faq_intro');
    $customBlocks = $request->getPost('prompt_custom_blocks');
    echo json_encode(
        FaqKnowledge::adminPreview(
            $question,
            $withAi,
            $faqKnowledge !== null ? (string)$faqKnowledge : null,
            $faqIntro !== null ? (string)$faqIntro : null,
            $customBlocks !== null ? (string)$customBlocks : null
        ),
        JSON_UNESCAPED_UNICODE
    );
    die();
}

global $draxter_aichat_default_option;

$textFields = [
    'AI_PROVIDER', 'GEMINI_API_KEY', 'GEMINI_MODEL', 'DEEPSEEK_API_KEY', 'DEEPSEEK_MODEL',
    'PERPLEXITY_API_KEY', 'PERPLEXITY_MODEL', 'CHAT_LOG_DIR', 'CHAT_WELCOME', 'CATALOG_SOURCE',
    'CATALOG_URL', 'CATALOG_PATH', 'CATALOG_IBLOCK_ID', 'CATALOG_REFRESH_MINUTES', 'CATALOG_PROFILE',
    'SHOP_NAME', 'SHOP_BRAND', 'SITE_URL', 'SHOP_SPECIALIZATION', 'BITRIX24_WEBHOOK_URL',
    'BITRIX24_ASSIGNED_ID', 'BITRIX24_SOURCE_ID', 'BITRIX24_SOURCE_LABEL', 'CRM_LEAD_TITLE_PREFIX',
    'CRM_EMAIL_TO',
    'YANDEX_METRIKA_COUNTER_ID', 'YANDEX_METRIKA_GOAL',
        'WIDGET_ACCENT_COLOR', 'WIDGET_FAB_COLOR', 'WIDGET_FAB_TITLE', 'WIDGET_FAB_SUBTITLE',
        'WIDGET_FAB_ALIGN_H', 'WIDGET_FAB_ALIGN_V', 'WIDGET_FAB_RIGHT', 'WIDGET_FAB_BOTTOM',
        'WIDGET_AGENT_NAME', 'WIDGET_STATUS_TEXT',
    'VOICE_STT_PROVIDER', 'VOICE_GEMINI_STT_MODEL', 'VOICE_WHISPER_API_KEY', 'VOICE_WHISPER_BASE_URL', 'VOICE_WHISPER_MODEL', 'VOICE_TTS_PROVIDER',
    'VOICE_MAX_SECONDS', 'VOICE_TTS_MAX_CHARS', 'VOICE_TTS_RATE',
    'RATE_LIMIT_PER_MINUTE', 'INTENT_SMALL_TALK_REGEX', 'INTENT_PRODUCT_KEYWORDS_REGEX',
    'INTENT_PURCHASE_INTENT_REGEX', 'INTENT_SHOPPING_INTENT_REGEX', 'INTENT_TOPIC_WORDS',
    'INTENT_MOTORCYCLE_QUERY_REGEX', 'INTENT_MOTORCYCLE_APPEND_KEYWORD',
];

$textareaFields = [
    'PROMPT_ROLE_LINE', 'PROMPT_SMALL_TALK_HINT', 'PROMPT_SEARCH_RULES', 'PROMPT_CATALOG_SYNONYMS',
    'PROMPT_STYLE_BRIEF', 'PROMPT_STYLE_DETAILED', 'PROMPT_RULES_COMMON', 'PROMPT_DOMAIN_RULES',
    'PROMPT_CATALOG_HEADER', 'PROMPT_LINK_RULES', 'PROMPT_CUSTOM_BLOCKS', 'PROMPT_FAQ_INTRO', 'PROMPT_FAQ_KNOWLEDGE',
    'MSG_LEAD_PHONE_CTA', 'MSG_LEAD_PHONE_CTA_PROMPT', 'MSG_PHONE_REPLY_OK', 'MSG_PHONE_REPLY_FALLBACK',
];

$flagFields = [
    'GEMINI_USE_VERTEX', 'CHAT_LOG_ENABLED', 'BITRIX24_LEAD_ENABLED', 'YANDEX_METRIKA_ENABLED', 'CURL_SSL_VERIFY',
    'WIDGET_ENABLED', 'WIDGET_AUTO_INJECT', 'WIDGET_SHOW_ONLINE', 'VOICE_ENABLED', 'VOICE_REPLY_ENABLED',
    'CRM_LOCAL_ENABLED', 'CRM_EMAIL_ENABLED',
];

$textareaFields[] = 'CRM_B24_FIELD_MAP';
$textareaFields[] = 'CRM_LOCAL_FIELD_MAP';
$textareaFields[] = 'AI_CUSTOM_PROVIDERS';
$textareaFields[] = 'VOICE_CUSTOM_STT_PROVIDERS';
$textareaFields[] = 'VOICE_CUSTOM_TTS_PROVIDERS';

$draxterAdminRedirect = static function (string $query = '') use ($moduleId, $siteId, $request): void {
    global $APPLICATION;
    $tab = trim((string)$request->getPost('tabControl_active_tab'));
    if ($tab !== '' && preg_match('/^edit_[a-z0-9_]+$/i', $tab)) {
        $query = ($query !== '' ? $query . '&' : '') . 'tabControl_active_tab=' . urlencode($tab);
    }
    $url = $APPLICATION->GetCurPage()
        . '?mid=' . urlencode($moduleId)
        . '&lang=' . LANGUAGE_ID
        . '&site_id=' . urlencode($siteId);
    if ($query !== '') {
        $url .= '&' . $query;
    }
    LocalRedirect($url);
};

$saveOptions = static function (array $data) use ($moduleId, $siteId, $textFields, $textareaFields, $flagFields): void {
    foreach (array_merge($textFields, $textareaFields) as $name) {
        if (array_key_exists($name, $data)) {
            Option::set($moduleId, $name, (string)$data[$name], $siteId);
        }
    }
    foreach ($flagFields as $flag) {
        Option::set($moduleId, $flag, ($data[$flag] ?? '') === 'Y' ? 'Y' : 'N', $siteId);
    }
};

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('import_from_config') === 'Y') {
    foreach (SiteConfig::exportFlatOptions() as $name => $value) {
        Option::set($moduleId, $name, $value, $siteId);
    }
    if ($request->getPost('refresh_catalog') === 'Y') {
        Catalog::refresh();
    }
    $draxterAdminRedirect('imported=1');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('reset_defaults') === 'Y') {
    include __DIR__ . '/default_options.php';
    if (is_array($draxter_aichat_default_option)) {
        foreach ($draxter_aichat_default_option as $name => $value) {
            Option::set($moduleId, $name, (string)$value, $siteId);
        }
    }
    $draxterAdminRedirect('reset=1');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('b24_map_template') === 'Y') {
    Option::set($moduleId, 'CRM_B24_FIELD_MAP', CrmFieldMapper::encodeMap(CrmFieldMapper::defaultB24Map()), $siteId);
    $draxterAdminRedirect('saved=1');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('local_map_template') === 'Y') {
    Option::set($moduleId, 'CRM_LOCAL_FIELD_MAP', CrmFieldMapper::encodeMap(CrmFieldMapper::defaultLocalMap()), $siteId);
    $draxterAdminRedirect('saved=1');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('b24_load_fields') === 'Y') {
    $_SESSION['DRAXTER_B24_FIELDS'] = implode(', ', Bitrix24Lead::fetchLeadFieldCodes());
    $draxterAdminRedirect('b24fields=1');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('purge_api_logs') === 'Y') {
    $days = max(1, (int)$request->getPost('purge_days'));
    ApiErrorLog::purgeOlderThan($days);
    $draxterAdminRedirect('purged=1&tabControl_active_tab=edit_logs');
}

if ($request->isPost() && check_bitrix_sessid() && $request->getPost('save') !== null) {
    $postData = $request->getPostList()->toArray();
    $providerUrlWarnings = [];
    if (array_key_exists('AI_CUSTOM_PROVIDERS', $postData)) {
        $rawProviders = (string)$postData['AI_CUSTOM_PROVIDERS'];
        try {
            $decoded = $rawProviders !== '' ? \Bitrix\Main\Web\Json::decode($rawProviders) : [];
        } catch (\Throwable $e) {
            $decoded = [];
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $providerUrlWarnings = CustomProviders::collectUrlWarnings($decoded);
        $postData['AI_CUSTOM_PROVIDERS'] = CustomProviders::encode($decoded);
    }
    if (array_key_exists('VOICE_CUSTOM_STT_PROVIDERS', $postData)) {
        $rawStt = (string)$postData['VOICE_CUSTOM_STT_PROVIDERS'];
        try {
            $decodedStt = $rawStt !== '' ? \Bitrix\Main\Web\Json::decode($rawStt) : [];
        } catch (\Throwable $e) {
            $decodedStt = [];
        }
        if (!is_array($decodedStt)) {
            $decodedStt = [];
        }
        $postData['VOICE_CUSTOM_STT_PROVIDERS'] = CustomVoiceProviders::encode($decodedStt, CustomVoiceProviders::STT_KINDS);
    }
    if (array_key_exists('VOICE_CUSTOM_TTS_PROVIDERS', $postData)) {
        $rawTts = (string)$postData['VOICE_CUSTOM_TTS_PROVIDERS'];
        try {
            $decodedTts = $rawTts !== '' ? \Bitrix\Main\Web\Json::decode($rawTts) : [];
        } catch (\Throwable $e) {
            $decodedTts = [];
        }
        if (!is_array($decodedTts)) {
            $decodedTts = [];
        }
        $postData['VOICE_CUSTOM_TTS_PROVIDERS'] = CustomVoiceProviders::encode($decodedTts, CustomVoiceProviders::TTS_KINDS);
    }
    $saveOptions($postData);

    if (!empty($_FILES['AVATAR_FILE']['tmp_name']) && is_uploaded_file($_FILES['AVATAR_FILE']['tmp_name'])) {
        if (class_exists('CFile')) {
            $oldId = (int)Option::get($moduleId, 'AVATAR_FILE_ID', '', $siteId);
            $fileId = \CFile::SaveFile($_FILES['AVATAR_FILE'], 'draxter.aichat');
            if ($fileId) {
                Option::set($moduleId, 'AVATAR_FILE_ID', (string)(int)$fileId, $siteId);
                if ($oldId > 0) {
                    \CFile::Delete($oldId);
                }
            }
        }
    }
    if ($request->getPost('delete_avatar') === 'Y') {
        $oldId = (int)Option::get($moduleId, 'AVATAR_FILE_ID', '', $siteId);
        if ($oldId > 0 && class_exists('CFile')) {
            \CFile::Delete($oldId);
        }
        Option::set($moduleId, 'AVATAR_FILE_ID', '', $siteId);
    }

    if ($request->getPost('refresh_catalog') === 'Y') {
        Catalog::refresh();
    }

    if ($providerUrlWarnings !== []) {
        $_SESSION['DRAXTER_AI_PROVIDER_URL_WARN'] = $providerUrlWarnings;
    }

    $draxterAdminRedirect('saved=1');
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit_widget', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_WIDGET') ?: 'Виджет', 'TITLE' => 'Внешний вид'],
    ['DIV' => 'edit_ai', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_AI') ?: 'AI', 'TITLE' => 'Провайдер'],
    ['DIV' => 'edit_prompts', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_PROMPTS') ?: 'Промпты', 'TITLE' => 'Системные промпты'],
    ['DIV' => 'edit_intent', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_INTENT') ?: 'Поведение бота', 'TITLE' => 'Как бот понимает сообщения клиента'],
    ['DIV' => 'edit_voice', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_VOICE') ?: 'Голос', 'TITLE' => 'Голосовой ввод и ответ'],
    ['DIV' => 'edit_catalog', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_CATALOG') ?: 'Каталог', 'TITLE' => 'Товары'],
    ['DIV' => 'edit_crm', 'TAB' => Loc::getMessage('DRAXTER_AICHAT_TAB_CRM') ?: 'CRM', 'TITLE' => 'Лиды и поля CRM'],
    ['DIV' => 'edit_stats', 'TAB' => 'Статистика', 'TITLE' => 'Статистика чатов'],
    ['DIV' => 'edit_logs', 'TAB' => 'Логи', 'TITLE' => 'Логи API и сессий'],
]);

$statsDays = (int)($request->get('stats_days') ?: 7);
if (!in_array($statsDays, [7, 30], true)) {
    $statsDays = 7;
}
$statsData = AdminStats::aggregate($statsDays);
$statsUrlBase = $APPLICATION->GetCurPage()
    . '?mid=' . urlencode($moduleId)
    . '&lang=' . LANGUAGE_ID
    . '&site_id=' . urlencode($siteId);
$viewSessionId = trim((string)$request->get('view_session'));
$viewSessionData = $viewSessionId !== '' ? AdminStats::getSession($viewSessionId) : null;
$logFilter = trim((string)$request->get('log_filter'));
$logFile = trim((string)$request->get('log_file'));
if ($logFile === '' && ApiErrorLog::listLogFiles() !== []) {
    $logFile = ApiErrorLog::listLogFiles()[0];
}
$logLines = $logFile !== '' ? ApiErrorLog::tailLines($logFile, 200, $logFilter !== '' ? $logFilter : null) : [];
$logRows = ApiErrorLog::enrichRows(ApiErrorLog::parseLines($logLines));
$logFiles = ApiErrorLog::listLogFiles();
$logsUrlBase = $statsUrlBase;
$chatLogDir = ChatLog::logDir();
$b24FieldCodesHint = (string)($_SESSION['DRAXTER_B24_FIELDS'] ?? '');
$aiProviderUrlWarnings = (array)($_SESSION['DRAXTER_AI_PROVIDER_URL_WARN'] ?? []);
unset($_SESSION['DRAXTER_AI_PROVIDER_URL_WARN']);

function draxter_aichat_opt(string $name, string $default = ''): string
{
    global $moduleId, $siteId;

    return htmlspecialcharsbx(Option::get($moduleId, $name, $default, $siteId));
}

function draxter_aichat_optarea(string $name, string $default = ''): string
{
    return htmlspecialcharsbx(draxter_aichat_opt_raw($name, $default));
}

function draxter_aichat_opt_raw(string $name, string $default = ''): string
{
    global $moduleId, $siteId;
    $v = Option::get($moduleId, $name, $default, $siteId);
    if ($v !== '') {
        return $v;
    }
    global $draxter_aichat_default_option;
    if (!isset($draxter_aichat_default_option)) {
        include __DIR__ . '/default_options.php';
    }

    return (string)($draxter_aichat_default_option[$name] ?? $default);
}

$ajaxHealthUrl = '/local/ajax/draxter_aichat.php?action=health';
$avatarId = (int)Option::get($moduleId, 'AVATAR_FILE_ID', '', $siteId);
$avatarPreview = '';
if ($avatarId > 0 && class_exists('CFile')) {
    $avatarPreview = (string)\CFile::GetPath($avatarId);
}
$defaultAvatarUrl = Settings::defaultAvatarUrl();
$accentColorValue = draxter_aichat_opt('WIDGET_ACCENT_COLOR', '#2563eb');
$fabColorValue = draxter_aichat_opt('WIDGET_FAB_COLOR');
if ($fabColorValue === '') {
    $fabColorValue = $accentColorValue;
}
$currentAiProvider = draxter_aichat_opt_raw('AI_PROVIDER', 'gemini');
$customAiProviders = CustomProviders::list(draxter_aichat_opt_raw('AI_CUSTOM_PROVIDERS', '[]'));
$customVoiceSttProviders = CustomVoiceProviders::sttList(draxter_aichat_opt_raw('VOICE_CUSTOM_STT_PROVIDERS', '[]'));
$customVoiceTtsProviders = CustomVoiceProviders::ttsList(draxter_aichat_opt_raw('VOICE_CUSTOM_TTS_PROVIDERS', '[]'));
$voiceSttPrefRaw = draxter_aichat_opt_raw('VOICE_STT_PROVIDER', 'auto');
$voiceTtsPrefRaw = draxter_aichat_opt_raw('VOICE_TTS_PROVIDER', 'browser');

function draxter_aichat_color_input(string $hex): string
{
    $hex = trim($hex);
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $hex, $m)) {
        $h = $m[1];
        $hex = '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '#2563eb';
    }

    return $hex;
}

function draxter_aichat_stats_bubble_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return '';
    }
    $lines = array_filter(array_map('trim', explode("\n", $text)), static function ($line) {
        return $line !== '';
    });

    return htmlspecialcharsbx(implode("\n", $lines));
}

function draxter_aichat_tab_header(string $title, string $subtitle = ''): void
{
    echo '<tr><td colspan="2" class="draxter-admin-tab-head"><div class="draxter-admin-toolbar">';
    echo '<div><span class="draxter-admin-toolbar-title">' . htmlspecialcharsbx($title) . '</span>';
    if ($subtitle !== '') {
        echo '<span class="draxter-admin-toolbar-sub">' . htmlspecialcharsbx($subtitle) . '</span>';
    }
    echo '</div></div></td></tr>';
}

function draxter_aichat_catalog_status(): void
{
    try {
        $info = Catalog::getInfo();
    } catch (\Throwable $e) {
        echo '<tr><td colspan="2"><div class="draxter-stats-empty">Не удалось загрузить каталог: '
            . htmlspecialcharsbx($e->getMessage()) . '</div></td></tr>';

        return;
    }
    $count = (int)($info['count'] ?? 0);
    $source = (string)($info['source'] ?? '—');
    echo '<tr><td colspan="2"><div class="draxter-admin-kpi">';
    echo '<div class="draxter-stats-kpi-card"><div class="draxter-stats-kpi-value">' . $count
        . '</div><div class="draxter-stats-kpi-label">товаров в каталоге</div></div>';
    echo '<div class="draxter-stats-kpi-card"><div class="draxter-stats-kpi-value draxter-admin-kpi-source">'
        . htmlspecialcharsbx($source) . '</div><div class="draxter-stats-kpi-label">источник данных</div></div>';
    echo '</div></td></tr>';
}

function draxter_aichat_log_http_class(int $code): string
{
    if ($code >= 500) {
        return 'draxter-logs-code--error';
    }
    if ($code === 429 || $code >= 400) {
        return 'draxter-logs-code--warn';
    }

    return 'draxter-logs-code--ok';
}

function draxter_aichat_logs_url(string $base, string $logFile = '', string $logFilter = ''): string
{
    $url = $base;
    if ($logFile !== '') {
        $url .= '&log_file=' . urlencode($logFile);
    }
    if ($logFilter !== '') {
        $url .= '&log_filter=' . urlencode($logFilter);
    }

    return $url . '&tabControl_active_tab=edit_logs#edit_logs';
}

$accentPicker = draxter_aichat_color_input($accentColorValue);
$fabPicker = draxter_aichat_color_input($fabColorValue);

require_once __DIR__ . '/options_tab_help.php';

?>
<style>
.draxter-aichat-opt-textarea {
    width: min(560px, 100%);
    max-width: 560px;
    box-sizing: border-box;
}
.draxter-faq-block,
.draxter-prompt-block,
.draxter-ai-provider-block {
    border: 1px solid #c4ced3;
    border-radius: 4px;
    padding: 10px 12px;
    margin-bottom: 10px;
    background: #fff;
    max-width: 640px;
}
.draxter-faq-block-head,
.draxter-prompt-block-head,
.draxter-ai-provider-block-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.draxter-ai-provider-block-head {
    margin-bottom: 12px;
}
.draxter-faq-block label,
.draxter-prompt-block label {
    display: block;
    margin-bottom: 8px;
}
.draxter-ai-provider-field {
    display: block;
    margin-bottom: 14px;
}
.draxter-ai-provider-field:last-child {
    margin-bottom: 0;
}
.draxter-ai-provider-field-label {
    display: block;
    font-weight: bold;
    margin: 0 0 4px;
}
.draxter-ai-provider-field .draxter-field-hint {
    margin: 0 0 6px;
}
.draxter-faq-block input[type="text"],
.draxter-faq-block textarea,
.draxter-prompt-block input[type="text"],
.draxter-prompt-block textarea,
.draxter-prompt-block select,
.draxter-ai-provider-block input[type="text"],
.draxter-ai-provider-block input[type="password"],
.draxter-ai-provider-block input[type="url"],
.draxter-ai-provider-block textarea,
.draxter-ai-provider-block select {
    width: 100%;
    box-sizing: border-box;
    margin-top: 0;
}
.draxter-faq-block input[type="text"],
.draxter-faq-block textarea,
.draxter-prompt-block input[type="text"],
.draxter-prompt-block textarea,
.draxter-prompt-block select {
    margin-top: 4px;
}
#draxter-ai-providers .draxter-ai-provider-block {
    margin-bottom: 16px;
}
#draxter-ai-providers .draxter-ai-provider-block:last-child {
    margin-bottom: 0;
}
.draxter-faq-block .draxter-faq-remove,
.draxter-prompt-block .draxter-prompt-remove,
.draxter-ai-provider-block .draxter-ai-provider-remove {
    color: #d04545;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
}
.draxter-aichat-help {
    max-width: 820px;
    line-height: 1.45;
}
.draxter-aichat-help h4 {
    margin: 16px 0 6px;
    font-size: 13px;
}
.draxter-aichat-help p,
.draxter-aichat-help ul {
    margin: 6px 0;
}
.draxter-aichat-help ul {
    padding-left: 20px;
}
.draxter-aichat-help li {
    margin-bottom: 4px;
}
.draxter-aichat-help dl {
    margin: 8px 0;
}
.draxter-aichat-help dt {
    font-weight: bold;
    margin-top: 8px;
}
.draxter-aichat-help dd {
    margin: 2px 0 0 0;
    color: #333;
}
.draxter-intent-intro {
    max-width: 720px;
}
.draxter-intent-section td {
    padding-top: 14px;
}
.draxter-field-hint {
    color: #555;
    font-size: 12px;
    line-height: 1.45;
    margin: 0 0 8px;
    max-width: 560px;
}
.draxter-intent-effect {
    display: inline-block;
    margin-bottom: 8px;
    padding: 4px 8px;
    background: #eef5ff;
    border-radius: 4px;
    font-size: 12px;
    color: #1e40af;
}
.draxter-aichat-flash {
    display: inline-block;
    max-width: 720px;
    margin: 0 0 12px;
    padding: 10px 14px;
    border-radius: 3px;
    font-size: 13px;
    line-height: 1.45;
    box-sizing: border-box;
}
.draxter-aichat-flash--ok {
    background: #e7f7e4;
    border: 1px solid #bbedbb;
    color: #3d6732;
}
.draxter-aichat-flash--warn {
    background: #fff8e6;
    border: 1px solid #f0d78c;
    color: #6b4e00;
}
.draxter-ai-provider-guide ol {
    margin: 8px 0 0 18px;
    line-height: 1.5;
}
.draxter-ai-provider-guide li {
    margin-bottom: 4px;
}
.draxter-stats {
    max-width: 960px;
    margin: 4px 0 16px;
}
.draxter-stats-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 16px;
    margin-bottom: 16px;
}
.draxter-stats-toolbar-title {
    font-weight: bold;
    font-size: 14px;
    color: #333;
}
.draxter-stats-periods {
    display: inline-flex;
    border: 1px solid #c4ced3;
    border-radius: 4px;
    overflow: hidden;
    background: #fff;
}
.draxter-stats-period {
    display: inline-block;
    padding: 6px 14px;
    font-size: 13px;
    text-decoration: none;
    color: #333;
    border-right: 1px solid #c4ced3;
}
.draxter-stats-period:last-child {
    border-right: none;
}
.draxter-stats-period:hover {
    background: #f5f7f8;
    text-decoration: none;
}
.draxter-stats-period.is-active {
    background: #eef5ff;
    color: #1e40af;
    font-weight: bold;
}
.draxter-stats-kpi {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.draxter-stats-kpi-card {
    background: #fff;
    border: 1px solid #dce1e5;
    border-radius: 6px;
    padding: 14px 16px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.draxter-stats-kpi-card--warn {
    border-color: #f0d78c;
    background: #fffdf5;
}
.draxter-stats-kpi-value {
    font-size: 28px;
    font-weight: bold;
    line-height: 1.1;
    color: #111;
}
.draxter-stats-kpi-card--warn .draxter-stats-kpi-value {
    color: #9a6700;
}
.draxter-stats-kpi-label {
    margin-top: 6px;
    font-size: 12px;
    color: #666;
}
.draxter-stats-section {
    margin-bottom: 22px;
}
.draxter-stats-section-title {
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: bold;
    color: #333;
}
.draxter-stats-phrases {
    margin: 0;
    padding: 0;
    list-style: none;
    border: 1px solid #dce1e5;
    border-radius: 6px;
    background: #fff;
    overflow: hidden;
}
.draxter-stats-phrases li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid #eef1f3;
    font-size: 13px;
}
.draxter-stats-phrases li:last-child {
    border-bottom: none;
}
.draxter-stats-phrases-count {
    flex-shrink: 0;
    color: #666;
    font-size: 12px;
}
.draxter-stats-empty {
    padding: 18px 14px;
    border: 1px dashed #c4ced3;
    border-radius: 6px;
    background: #fafbfc;
    color: #666;
    font-size: 13px;
    line-height: 1.5;
}
.draxter-stats-table-wrap {
    border: 1px solid #dce1e5;
    border-radius: 6px;
    overflow: hidden;
    background: #fff;
}
.draxter-stats-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.draxter-stats-table th,
.draxter-stats-table td {
    padding: 10px 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid #eef1f3;
}
.draxter-stats-table th {
    background: #f5f7f8;
    font-weight: bold;
    color: #444;
    white-space: nowrap;
}
.draxter-stats-table tr:last-child td {
    border-bottom: none;
}
.draxter-stats-table tr:hover td {
    background: #fafbfc;
}
.draxter-stats-preview {
    color: #555;
    max-width: 280px;
    line-height: 1.4;
}
.draxter-stats-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: bold;
    line-height: 1.4;
}
.draxter-stats-badge--lead {
    background: #e7f7e4;
    color: #3d6732;
}
.draxter-stats-badge--error {
    background: #fff0f0;
    color: #b42318;
}
.draxter-stats-badge--ok {
    background: #eef5ff;
    color: #1e40af;
}
.draxter-stats-btn {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 4px;
    border: 1px solid #c4ced3;
    background: #fff;
    color: #333;
    font-size: 12px;
    text-decoration: none;
    white-space: nowrap;
}
.draxter-stats-btn:hover {
    background: #eef5ff;
    border-color: #9db7e8;
    color: #1e40af;
    text-decoration: none;
}
.draxter-stats-session {
    margin: 0 0 22px;
    border: 1px solid #9db7e8;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 2px 8px rgba(30, 64, 175, 0.08);
    overflow: hidden;
}
.draxter-stats-session-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px;
    background: #eef5ff;
    border-bottom: 1px solid #d5e3ff;
}
.draxter-stats-session-title {
    margin: 0;
    font-size: 15px;
    color: #1e3a8a;
}
.draxter-stats-session-meta {
    margin: 6px 0 0;
    font-size: 12px;
    color: #555;
    line-height: 1.5;
}
.draxter-stats-session-close {
    font-size: 12px;
    text-decoration: none;
    color: #555;
    white-space: nowrap;
}
.draxter-stats-session-close:hover {
    color: #b42318;
}
.draxter-stats-chat {
    max-height: 520px;
    overflow: auto;
    padding: 14px 16px;
    background: #f8fafc;
}
.draxter-stats-turn {
    margin-bottom: 12px;
}
.draxter-stats-turn:last-child {
    margin-bottom: 0;
}
.draxter-stats-turn-time {
    margin-bottom: 4px;
    font-size: 11px;
    color: #888;
}
.draxter-stats-bubble {
    display: inline-block;
    max-width: 92%;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.4;
    white-space: pre-line;
    word-break: break-word;
    vertical-align: top;
    box-sizing: border-box;
}
.draxter-stats-bubble--user {
    background: #dbeafe;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
}
.draxter-stats-bubble--bot {
    display: block;
    background: #fff;
    border: 1px solid #dce1e5;
    color: #222;
    margin-top: 6px;
}
.draxter-stats-bubble-label {
    display: block;
    margin: 0 0 2px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    opacity: 0.7;
    line-height: 1.2;
}
.draxter-stats-turn-error {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 4px;
    background: #fff0f0;
    border: 1px solid #fecaca;
    color: #b42318;
    font-size: 12px;
}
.draxter-logs {
    max-width: 960px;
    margin: 4px 0 16px;
}
.draxter-logs-section {
    margin-bottom: 22px;
}
.draxter-logs-section-title {
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: bold;
    color: #333;
}
.draxter-logs-card {
    border: 1px solid #dce1e5;
    border-radius: 6px;
    background: #fff;
    padding: 14px 16px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.draxter-logs-settings-row {
    margin-bottom: 12px;
}
.draxter-logs-settings-row:last-child {
    margin-bottom: 0;
}
.draxter-logs-settings-row label {
    display: block;
    font-weight: bold;
    font-size: 13px;
    margin-bottom: 6px;
    color: #333;
}
.draxter-logs-settings-row input[type="text"] {
    width: min(100%, 560px);
    box-sizing: border-box;
}
.draxter-logs-hint {
    margin: 6px 0 0;
    font-size: 12px;
    color: #666;
    line-height: 1.45;
}
.draxter-logs-path {
    display: inline-block;
    margin-top: 8px;
    padding: 4px 8px;
    border-radius: 4px;
    background: #f5f7f8;
    border: 1px solid #eef1f3;
    font-size: 11px;
    color: #555;
    word-break: break-all;
}
.draxter-logs-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
    margin-bottom: 12px;
}
.draxter-logs-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.draxter-logs-filter-form input[type="text"] {
    min-width: 220px;
    padding: 5px 8px;
}
.draxter-logs-quick-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
}
.draxter-logs-chip {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    border: 1px solid #dce1e5;
    background: #fff;
    font-size: 12px;
    text-decoration: none;
    color: #444;
}
.draxter-logs-chip:hover {
    background: #eef5ff;
    border-color: #9db7e8;
    color: #1e40af;
    text-decoration: none;
}
.draxter-logs-chip.is-active {
    background: #eef5ff;
    border-color: #9db7e8;
    color: #1e40af;
    font-weight: bold;
}
.draxter-logs-meta {
    margin-bottom: 10px;
    font-size: 12px;
    color: #666;
}
.draxter-logs-message {
    max-width: 420px;
    line-height: 1.4;
    word-break: break-word;
}
.draxter-logs-code {
    display: inline-block;
    min-width: 36px;
    text-align: center;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
}
.draxter-logs-code--ok {
    background: #e7f7e4;
    color: #3d6732;
}
.draxter-logs-code--warn {
    background: #fff8e6;
    color: #9a6700;
}
.draxter-logs-code--error {
    background: #fff0f0;
    color: #b42318;
}
.draxter-logs-code--quota {
    background: #fff4e5;
    border: 1px solid #f5c26b;
    color: #9a6700;
}
.draxter-logs-row--quota {
    background: #fffbf3;
}
.draxter-logs-row--warn {
    background: #fffdf8;
}
.draxter-logs-row--error {
    background: #fff8f8;
}
.draxter-logs-error-type {
    font-weight: 600;
    font-size: 12px;
    color: #333;
    white-space: nowrap;
}
.draxter-logs-error-type--quota {
    color: #9a6700;
}
.draxter-logs-detail {
    font-size: 12px;
    color: #555;
    line-height: 1.45;
    max-width: 420px;
    word-break: break-word;
}
.draxter-logs-detail.is-empty {
    color: #999;
    font-style: italic;
}
.draxter-logs-action {
    font-size: 12px;
    color: #333;
}
.draxter-logs-action code {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    color: #888;
}
.draxter-logs-purge {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #eef1f3;
}
.draxter-logs-purge-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}
.draxter-logs-purge-form input[type="number"] {
    width: 64px;
    padding: 4px 6px;
}
#edit_widget,
#edit_ai,
#edit_prompts,
#edit_intent,
#edit_voice,
#edit_catalog,
#edit_crm {
    max-width: 960px;
    padding: 4px 0 20px;
}
.draxter-admin-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px 16px;
    margin-bottom: 4px;
    padding-bottom: 12px;
    border-bottom: 1px solid #dce1e5;
}
.draxter-admin-toolbar-title {
    font-size: 16px;
    font-weight: bold;
    color: #1e3a8a;
}
.draxter-admin-toolbar-sub {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    font-weight: normal;
    color: #666;
    line-height: 1.45;
}
.draxter-admin-tab-head td {
    padding: 8px 0 0 !important;
    border: none !important;
    background: transparent !important;
}
.draxter-admin-kpi {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 0 0 14px;
}
.draxter-admin-kpi-source {
    font-size: 13px !important;
    line-height: 1.35;
    word-break: break-word;
}
#edit_widget table,
#edit_ai table,
#edit_prompts table,
#edit_intent table,
#edit_voice table,
#edit_catalog table,
#edit_crm table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 10px;
}
#edit_widget table > tbody > tr > td:first-child:not([colspan]),
#edit_ai table > tbody > tr > td:first-child:not([colspan]),
#edit_prompts table > tbody > tr > td:first-child:not([colspan]),
#edit_intent table > tbody > tr > td:first-child:not([colspan]),
#edit_voice table > tbody > tr > td:first-child:not([colspan]),
#edit_catalog table > tbody > tr > td:first-child:not([colspan]),
#edit_crm table > tbody > tr > td:first-child:not([colspan]) {
    width: 32%;
    min-width: 160px;
    vertical-align: top;
    padding: 12px 14px !important;
    background: #f8fafc;
    border: 1px solid #dce1e5;
    border-right: none;
    border-radius: 6px 0 0 6px;
    font-weight: 600;
    font-size: 13px;
    color: #333;
    line-height: 1.45;
}
#edit_widget table > tbody > tr > td:last-child:not([colspan]),
#edit_ai table > tbody > tr > td:last-child:not([colspan]),
#edit_prompts table > tbody > tr > td:last-child:not([colspan]),
#edit_intent table > tbody > tr > td:last-child:not([colspan]),
#edit_voice table > tbody > tr > td:last-child:not([colspan]),
#edit_catalog table > tbody > tr > td:last-child:not([colspan]),
#edit_crm table > tbody > tr > td:last-child:not([colspan]) {
    padding: 12px 14px !important;
    background: #fff;
    border: 1px solid #dce1e5;
    border-left: none;
    border-radius: 0 6px 6px 0;
    vertical-align: top;
    line-height: 1.45;
}
#edit_widget table > tbody > tr > td[colspan="2"],
#edit_ai table > tbody > tr > td[colspan="2"],
#edit_prompts table > tbody > tr > td[colspan="2"],
#edit_intent table > tbody > tr > td[colspan="2"],
#edit_voice table > tbody > tr > td[colspan="2"],
#edit_catalog table > tbody > tr > td[colspan="2"],
#edit_crm table > tbody > tr > td[colspan="2"] {
    background: transparent !important;
    border: none !important;
    padding: 2px 0 !important;
}
#edit_widget table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_ai table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_prompts table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_intent table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_voice table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_catalog table > tbody > tr > td[colspan="2"] > strong:first-child,
#edit_crm table > tbody > tr > td[colspan="2"] > strong:first-child {
    display: block;
    margin: 16px 0 6px;
    padding: 10px 14px;
    background: #eef5ff;
    border: 1px solid #d5e3ff;
    border-radius: 6px;
    font-size: 13px;
    color: #1e3a8a;
}
#edit_widget input[type="text"],
#edit_widget input[type="password"],
#edit_widget input[type="url"],
#edit_widget input[type="number"],
#edit_widget select,
#edit_widget textarea,
#edit_ai input[type="text"],
#edit_ai input[type="password"],
#edit_ai select,
#edit_ai textarea,
#edit_prompts input[type="text"],
#edit_prompts select,
#edit_prompts textarea,
#edit_intent input[type="text"],
#edit_intent textarea,
#edit_voice input[type="text"],
#edit_voice select,
#edit_voice textarea,
#edit_catalog input[type="text"],
#edit_catalog select,
#edit_catalog textarea,
#edit_crm input[type="text"],
#edit_crm textarea {
    max-width: 100%;
    box-sizing: border-box;
    padding: 6px 8px;
    border: 1px solid #c4ced3;
    border-radius: 4px;
    font-size: 13px;
}
#edit_prompts textarea,
#edit_intent textarea,
#edit_crm textarea,
.draxter-aichat-opt-textarea {
    width: min(100%, 680px);
    min-height: 64px;
    line-height: 1.45;
}
#edit_crm textarea[style*="monospace"],
#edit_ai textarea[style*="display:none"] {
    font-family: inherit;
}
.draxter-admin-note {
    margin: 0 0 10px;
    padding: 10px 14px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid #eef1f3;
    font-size: 12px;
    color: #555;
    line-height: 1.45;
}
.draxter-admin-help {
    margin-top: 18px;
    border: 1px solid #dce1e5;
    border-radius: 6px;
    background: #fff;
    padding: 14px 16px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.draxter-admin-help-title {
    margin: 0 0 10px;
    font-size: 13px;
    font-weight: bold;
    color: #1e3a8a;
}
.draxter-admin-help .draxter-aichat-help {
    padding: 0;
    background: transparent;
    border: none;
}
.draxter-intent-intro .adm-info-message,
.draxter-ai-provider-guide .adm-info-message,
.draxter-admin-callout .adm-info-message {
    border-radius: 6px;
    border: 1px solid #d5e3ff;
    background: #f8fbff;
    padding: 14px 16px;
    max-width: 100%;
    box-shadow: 0 1px 2px rgba(30, 64, 175, 0.06);
}
.draxter-admin-callout {
    margin: 0 0 4px;
}
#draxter-faq-preview-out {
    display: none;
    margin-top: 10px;
    padding: 12px 14px;
    border-radius: 6px;
    border: 1px solid #dce1e5;
    background: #f8fafc;
    font-size: 12px;
    line-height: 1.45;
    max-width: 680px;
    white-space: pre-wrap;
}
.draxter-admin-import {
    margin-top: 16px;
    max-width: 960px;
}
.draxter-admin-import .draxter-logs-card {
    margin-top: 0;
}
.draxter-admin-site-select {
    margin-bottom: 14px;
    padding: 10px 14px;
    border: 1px solid #dce1e5;
    border-radius: 6px;
    background: #fff;
    max-width: 960px;
}
.draxter-faq-block,
.draxter-prompt-block,
.draxter-ai-provider-block {
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
</style>
<?php if ($request->get('imported') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">
    Настройки импортированы из <code><?= htmlspecialcharsbx($configPath) ?></code>
</div>
<?php endif; ?>
<?php if ($request->get('saved') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">Сохранено.</div>
<?php endif; ?>
<?php if ($request->get('purged') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">Старые api-логи удалены.</div>
<?php endif; ?>
<?php if ($aiProviderUrlWarnings !== []): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--warn">
    <strong>Проверьте адреса API у своих провайдеров:</strong>
    <ul style="margin:6px 0 0 18px;padding:0">
        <?php foreach ($aiProviderUrlWarnings as $warn): ?>
            <li><?= htmlspecialcharsbx((string)$warn) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if ($request->get('reset') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">Сброшено к значениям по умолчанию.</div>
<?php endif; ?>

<div class="adm-info-message-wrap">
    <div class="adm-info-message">
        <strong>Настройки сайта:</strong> приоритет у полей ниже (база). Файл
        <code><?= htmlspecialcharsbx($configPath) ?></code>
        <?= $configLoaded ? ' найден — подставляется только если поле выше пустое.' : ' не найден — все настройки в базе.' ?>
        <br><a href="<?= htmlspecialcharsbx($ajaxHealthUrl) ?>" target="_blank" rel="noopener">Проверка API (health)</a>
    </div>
</div>

<?php if (class_exists('CSite')): ?>
<form method="get" class="draxter-admin-site-select">
    <input type="hidden" name="mid" value="<?= htmlspecialcharsbx($moduleId) ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <label>Сайт (мультисайт): </label>
    <select name="site_id" onchange="this.form.submit()">
        <option value="">— по умолчанию —</option>
        <?php
        $rs = \CSite::GetList('sort', 'asc', ['ACTIVE' => 'Y']);
        while ($site = $rs->Fetch()):
            ?>
            <option value="<?= htmlspecialcharsbx($site['LID']) ?>" <?= $siteId === $site['LID'] ? 'selected' : '' ?>>
                [<?= htmlspecialcharsbx($site['LID']) ?>] <?= htmlspecialcharsbx($site['NAME']) ?>
            </option>
        <?php endwhile; ?>
    </select>
</form>
<?php endif; ?>

<?php $tabControl->Begin(); ?>
<form method="post" enctype="multipart/form-data" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>">

    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('Виджет', 'Внешний вид чата, кнопка на сайте и приветствие'); ?>
    <tr><td width="35%">Показывать виджет</td><td><input type="checkbox" name="WIDGET_ENABLED" value="Y" <?= draxter_aichat_opt('WIDGET_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Приветствие</td><td><textarea name="CHAT_WELCOME" rows="3" cols="70"><?= draxter_aichat_optarea('CHAT_WELCOME') ?></textarea></td></tr>
    <tr><td>Аватар</td><td>
        <img src="<?= htmlspecialcharsbx($avatarPreview !== '' ? $avatarPreview : $defaultAvatarUrl) ?>" alt="" style="max-height:48px;display:block;margin-bottom:8px;border-radius:50%">
        <p style="max-width:520px;margin:0 0 8px"><small>PNG/JPG — заменить аватар по умолчанию. «Удалить» — снова аватар по умолчанию.</small></p>
        <input type="file" name="AVATAR_FILE" accept="image/png,image/jpeg,image/webp">
        <label><input type="checkbox" name="delete_avatar" value="Y"> Удалить загруженный</label>
    </td></tr>
    <tr><td>Цвет акцента (шапка чата)</td><td>
        <input type="color" id="drax-accent-picker" value="<?= htmlspecialcharsbx($accentPicker) ?>" style="vertical-align:middle;width:48px;height:32px;padding:0;border:1px solid #ccc">
        <input type="text" name="WIDGET_ACCENT_COLOR" id="drax-accent-text" value="<?= draxter_aichat_opt('WIDGET_ACCENT_COLOR', '#2563eb') ?>" size="12" placeholder="#2563eb">
    </td></tr>
    <tr><td colspan="2"><strong>Кнопка открытия чата (FAB)</strong></td></tr>
    <tr><td>Цвет кнопки</td><td>
        <input type="color" id="drax-fab-picker" value="<?= htmlspecialcharsbx($fabPicker) ?>" style="vertical-align:middle;width:48px;height:32px;padding:0;border:1px solid #ccc">
        <input type="text" name="WIDGET_FAB_COLOR" id="drax-fab-text" value="<?= draxter_aichat_opt('WIDGET_FAB_COLOR') ?>" size="12" placeholder="пусто = цвет акцента">
        <small> Оставьте текст пустым — будет цвет акцента.</small>
    </td></tr>
    <tr><td>Название на кнопке</td><td><input type="text" name="WIDGET_FAB_TITLE" value="<?= draxter_aichat_opt('WIDGET_FAB_TITLE', 'Чат') ?>" size="30" placeholder="Чат"></td></tr>
    <tr><td>Текст «спросить о товаре»</td><td><input type="text" name="WIDGET_FAB_SUBTITLE" value="<?= draxter_aichat_opt('WIDGET_FAB_SUBTITLE', 'Спросить о товаре') ?>" size="40" placeholder="Спросить о товаре"></td></tr>
    <tr><td>Горизонталь</td><td>
        <select name="WIDGET_FAB_ALIGN_H">
            <option value="right" <?= draxter_aichat_opt('WIDGET_FAB_ALIGN_H', 'right') === 'right' ? 'selected' : '' ?>>Справа</option>
            <option value="left" <?= draxter_aichat_opt('WIDGET_FAB_ALIGN_H') === 'left' ? 'selected' : '' ?>>Слева</option>
        </select>
        отступ <input type="text" name="WIDGET_FAB_RIGHT" value="<?= draxter_aichat_opt('WIDGET_FAB_RIGHT', '24') ?>" size="6"> px
    </td></tr>
    <tr><td>Вертикаль</td><td>
        <select name="WIDGET_FAB_ALIGN_V">
            <option value="bottom" <?= draxter_aichat_opt('WIDGET_FAB_ALIGN_V', 'bottom') === 'bottom' ? 'selected' : '' ?>>Снизу</option>
            <option value="top" <?= draxter_aichat_opt('WIDGET_FAB_ALIGN_V') === 'top' ? 'selected' : '' ?>>Сверху</option>
        </select>
        отступ <input type="text" name="WIDGET_FAB_BOTTOM" value="<?= draxter_aichat_opt('WIDGET_FAB_BOTTOM', '24') ?>" size="6"> px
    </td></tr>
    <tr><td>Имя консультанта</td><td><input type="text" name="WIDGET_AGENT_NAME" value="<?= draxter_aichat_opt('WIDGET_AGENT_NAME') ?>" size="40" placeholder="пусто = название магазина"></td></tr>
    <tr><td>Показывать статус «онлайн»</td><td><input type="checkbox" name="WIDGET_SHOW_ONLINE" value="Y" <?= draxter_aichat_opt('WIDGET_SHOW_ONLINE', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Текст статуса</td><td><input type="text" name="WIDGET_STATUS_TEXT" value="<?= draxter_aichat_opt('WIDGET_STATUS_TEXT', 'Онлайн • отвечает сразу') ?>" size="50" placeholder="Онлайн • отвечает сразу"></td></tr>
    <tr><td>Автоподключение на всех страницах</td><td><input type="checkbox" name="WIDGET_AUTO_INJECT" value="Y" <?= draxter_aichat_opt('WIDGET_AUTO_INJECT', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <?php draxter_aichat_tab_help('widget'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('AI', 'Провайдер нейросети, ключи API и свои сервисы'); ?>
    <tr><td>Провайдер</td><td>
        <select name="AI_PROVIDER" id="draxter-ai-provider-select">
            <option value="gemini" <?= $currentAiProvider === 'gemini' ? 'selected' : '' ?>>Gemini</option>
            <option value="deepseek" <?= $currentAiProvider === 'deepseek' ? 'selected' : '' ?>>DeepSeek</option>
            <option value="perplexity" <?= $currentAiProvider === 'perplexity' ? 'selected' : '' ?>>Perplexity</option>
            <?php foreach ($customAiProviders as $customProvider): ?>
                <option value="<?= htmlspecialcharsbx($customProvider['id']) ?>" class="draxter-ai-provider-custom-opt" <?= $currentAiProvider === $customProvider['id'] ? 'selected' : '' ?>><?= htmlspecialcharsbx($customProvider['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </td></tr>
    <tr><td colspan="2">
        <div class="adm-info-message-wrap draxter-ai-provider-guide draxter-admin-callout">
            <div class="adm-info-message">
                <strong>Как подключить свою нейросеть (Mistral, OpenRouter, Groq и др.)</strong>
                <ol>
                    <li>Зарегистрируйтесь у выбранного сервиса и создайте <strong>API-ключ</strong> в личном кабинете (раздел API / Keys).</li>
                    <li>Скопируйте из документации сервиса <strong>полный адрес запроса</strong> — обычно заканчивается на <code>/v1/chat/completions</code>. Это не адрес вашего сайта и не ссылка на эту страницу админки.</li>
                    <li>Нажмите «+ Добавить провайдер» ниже, заполните поля (можно начать с кнопки «Пример: Mistral» или «Пример: OpenRouter»).</li>
                    <li>Нажмите <strong>«Сохранить»</strong> внизу страницы. Затем в списке <strong>«Провайдер»</strong> в начале вкладки выберите добавленный сервис.</li>
                    <li>Проверьте работу: ссылка <a href="<?= htmlspecialcharsbx($ajaxHealthUrl) ?>" target="_blank" rel="noopener">Проверка API (health)</a> вверху страницы.</li>
                </ol>
                <p style="margin:8px 0 0;color:#555;font-size:12px">Примеры адресов: Mistral — <code>https://api.mistral.ai/v1/chat/completions</code>; OpenRouter — <code>https://openrouter.ai/api/v1/chat/completions</code>.</p>
            </div>
        </div>
    </td></tr>
    <tr><td>Настройка провайдеров</td><td>
        <p class="draxter-field-hint" style="margin:0 0 8px">Кнопки ниже подставят типовые значения — <strong>API-ключ</strong> и при необходимости сайт в доп. заголовках нужно указать свои.</p>
        <p style="margin:0 0 10px">
            <button type="button" class="adm-btn" id="draxter-ai-provider-preset-mistral">Пример: Mistral</button>
            <button type="button" class="adm-btn" id="draxter-ai-provider-preset-openrouter" style="margin-left:6px">Пример: OpenRouter</button>
        </p>
        <div id="draxter-ai-providers"></div>
        <p style="margin:8px 0">
            <button type="button" class="adm-btn" id="draxter-ai-provider-add">+ Добавить провайдер</button>
        </p>
        <textarea name="AI_CUSTOM_PROVIDERS" id="draxter-ai-providers-raw" style="display:none"><?= draxter_aichat_optarea('AI_CUSTOM_PROVIDERS', '[]') ?></textarea>
        <p class="draxter-field-hint" style="margin-top:8px">После сохранения название провайдера появится в выпадающем списке <strong>«Провайдер»</strong> в начале этой вкладки.</p>
    </td></tr>
    <tr><td>GEMINI_API_KEY</td><td><input type="password" name="GEMINI_API_KEY" value="<?= draxter_aichat_opt('GEMINI_API_KEY') ?>" size="60" autocomplete="off"></td></tr>
    <tr><td>GEMINI_MODEL</td><td><input type="text" name="GEMINI_MODEL" value="<?= draxter_aichat_opt('GEMINI_MODEL', 'gemini-2.5-flash') ?>" size="40"></td></tr>
    <tr><td>GEMINI_USE_VERTEX</td><td><input type="checkbox" name="GEMINI_USE_VERTEX" value="Y" <?= draxter_aichat_opt('GEMINI_USE_VERTEX', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>DEEPSEEK_API_KEY</td><td><input type="password" name="DEEPSEEK_API_KEY" value="<?= draxter_aichat_opt('DEEPSEEK_API_KEY') ?>" size="60" autocomplete="off"></td></tr>
    <tr><td>DEEPSEEK_MODEL</td><td><input type="text" name="DEEPSEEK_MODEL" value="<?= draxter_aichat_opt('DEEPSEEK_MODEL', 'deepseek-chat') ?>" size="40"></td></tr>
    <tr><td>PERPLEXITY_API_KEY</td><td><input type="password" name="PERPLEXITY_API_KEY" value="<?= draxter_aichat_opt('PERPLEXITY_API_KEY') ?>" size="60" autocomplete="off"></td></tr>
    <tr><td>PERPLEXITY_MODEL</td><td><input type="text" name="PERPLEXITY_MODEL" value="<?= draxter_aichat_opt('PERPLEXITY_MODEL', 'sonar') ?>" size="40" placeholder="sonar, sonar-pro, sonar-reasoning-pro"></td></tr>
    <tr><td>Проверка SSL (curl)</td><td><input type="checkbox" name="CURL_SSL_VERIFY" value="Y" <?= draxter_aichat_opt('CURL_SSL_VERIFY', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <?php draxter_aichat_tab_help('ai'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('Промпты', 'Инструкции для нейросети, FAQ и проверка ответов'); ?>
    <tr><td colspan="2"><p class="draxter-admin-note">Плейсхолдеры в текстах: <code>{shop_name}</code>, <code>{brand}</code></p></td></tr>
    <tr><td>Роль консультанта</td><td><textarea name="PROMPT_ROLE_LINE" rows="2" cols="70"><?= draxter_aichat_optarea('PROMPT_ROLE_LINE') ?></textarea></td></tr>
    <tr><td>Общие вопросы о боте</td><td><textarea name="PROMPT_SMALL_TALK_HINT" rows="2" cols="70"><?= draxter_aichat_optarea('PROMPT_SMALL_TALK_HINT') ?></textarea><br><small>«Кто ты», «привет» — без товаров и цен</small></td></tr>
    <tr><td>Правила подбора</td><td><textarea name="PROMPT_SEARCH_RULES" rows="6" cols="70"><?= draxter_aichat_optarea('PROMPT_SEARCH_RULES') ?></textarea></td></tr>
    <tr><td>Синонимы каталога</td><td><input type="text" name="PROMPT_CATALOG_SYNONYMS" value="<?= draxter_aichat_opt('PROMPT_CATALOG_SYNONYMS') ?>" size="70"></td></tr>
    <tr><td>Стиль краткий</td><td><textarea name="PROMPT_STYLE_BRIEF" rows="3" cols="70"><?= draxter_aichat_optarea('PROMPT_STYLE_BRIEF') ?></textarea></td></tr>
    <tr><td>Стиль подробный</td><td><textarea name="PROMPT_STYLE_DETAILED" rows="3" cols="70"><?= draxter_aichat_optarea('PROMPT_STYLE_DETAILED') ?></textarea></td></tr>
    <tr><td>Общие правила</td><td><textarea name="PROMPT_RULES_COMMON" rows="2" cols="70"><?= draxter_aichat_optarea('PROMPT_RULES_COMMON') ?></textarea></td></tr>
    <tr><td>Правила ссылок</td><td><textarea name="PROMPT_LINK_RULES" rows="2" cols="70"><?= draxter_aichat_optarea('PROMPT_LINK_RULES') ?></textarea><br><small>Как нейросеть оформляет ссылки на товары в тексте ответа</small></td></tr>
    <tr><td>Заголовок каталога</td><td><input type="text" name="PROMPT_CATALOG_HEADER" value="<?= draxter_aichat_opt('PROMPT_CATALOG_HEADER') ?>" size="70"></td></tr>
    <tr><td>Доп. правила (по строке)</td><td><textarea name="PROMPT_DOMAIN_RULES" rows="4" cols="70"><?= draxter_aichat_optarea('PROMPT_DOMAIN_RULES') ?></textarea></td></tr>
    <tr><td colspan="2"><strong>Свои промпты</strong> — дополнительные инструкции для нейросети</td></tr>
    <tr><td>Блоки промптов</td><td>
        <div id="draxter-prompt-blocks"></div>
        <p style="margin:8px 0">
            <button type="button" class="adm-btn" id="draxter-prompt-add-block">+ Добавить промпт</button>
        </p>
        <textarea name="PROMPT_CUSTOM_BLOCKS" id="draxter-prompt-blocks-raw" style="display:none"><?= draxter_aichat_optarea('PROMPT_CUSTOM_BLOCKS') ?></textarea>
        <div class="adm-info-message-wrap draxter-admin-callout" style="max-width:680px;margin-top:8px">
            <div class="adm-info-message">
            <p style="margin:0">Каждый блок — отдельная инструкция. Плейсхолдеры: <code>{shop_name}</code>, <code>{brand}</code>. Режим «По вопросу клиента» — промпт добавится, если сообщение похоже на указанный пример вопроса.</p>
            </div>
        </div>
    </td></tr>
    <tr><td colspan="2"><strong>Справочник ответов (FAQ)</strong> — доставка, оплата, дилеры, техника вне каталога</td></tr>
    <tr><td>Инструкция для AI</td><td><textarea name="PROMPT_FAQ_INTRO" rows="6" class="draxter-aichat-opt-textarea"><?= draxter_aichat_optarea('PROMPT_FAQ_INTRO') ?></textarea></td></tr>
    <tr><td>Справочник (блоки)</td><td>
        <div id="draxter-faq-blocks"></div>
        <p style="margin:8px 0">
            <button type="button" class="adm-btn" id="draxter-faq-add-block">+ Добавить блок</button>
            <select id="draxter-faq-template-select" style="margin-left:8px">
                <option value="">— готовый шаблон —</option>
                <option value="delivery">Доставка</option>
                <option value="payment">Оплата</option>
                <option value="dealers">Дилеры и города</option>
                <option value="clutch">Сцепление и трансмиссия</option>
                <option value="reducer">Редуктор мини-трактора</option>
                <option value="bucket">Ковш и стрела</option>
            </select>
            <button type="button" class="adm-btn" id="draxter-faq-add-template">Добавить из шаблона</button>
        </p>
        <textarea name="PROMPT_FAQ_KNOWLEDGE" id="draxter-faq-knowledge-raw" style="display:none"><?= draxter_aichat_optarea('PROMPT_FAQ_KNOWLEDGE') ?></textarea>
        <div class="adm-info-message-wrap draxter-admin-callout" style="max-width:680px;margin-top:8px">
            <div class="adm-info-message">
            <strong>Как это работает</strong>
            <p style="margin:8px 0 0">Каждый блок — отдельная тема. Заполните ключевые слова (через запятую), заголовок и текст ответа. Кнопка «+ Добавить блок» создаёт пустой блок с нужными полями.</p>
            </div>
        </div>
    </td></tr>
    <tr><td colspan="2"><strong>Проверка FAQ</strong></td></tr>
    <tr><td>Тестовый вопрос</td><td>
        <input type="text" id="draxter-faq-question" size="70" placeholder="Например: доставка до Екатеринбурга">
        <label style="display:block;margin-top:8px">
            <input type="checkbox" id="draxter-faq-with-ai" value="Y" checked>
            Запросить ответ нейросети (расход API, ~5–15 сек)
        </label>
        <button type="button" class="adm-btn" id="draxter-faq-preview-btn" style="margin-top:8px">Проверить FAQ</button>
        <pre id="draxter-faq-preview-out"></pre>
    </td></tr>
    <?php draxter_aichat_tab_help('prompts'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('Поведение бота', 'Как модуль распознаёт тип сообщения клиента'); ?>
    <tr><td colspan="2">
        <div class="adm-info-message-wrap draxter-intent-intro draxter-admin-callout">
            <div class="adm-info-message">
                <strong>Зачем эта вкладка</strong>
                <p style="margin:8px 0">Перед ответом модуль смотрит на <strong>последнее сообщение клиента</strong> и решает, что делать дальше:</p>
                <ul style="margin:8px 0 8px 18px;line-height:1.5">
                    <li><strong>Привет / «кто ты»</strong> — короткий ответ о боте, без каталога и без просьбы телефона.</li>
                    <li><strong>«Хочу купить», «оформить заказ»</strong> — бот сильнее просит номер для менеджера.</li>
                    <li><strong>Вопрос про товар</strong> — подбор из каталога; тематические слова помогают точнее определить, что искать.</li>
                </ul>
                <p style="margin:0;color:#555;font-size:12px">В большинстве случаев достаточно значений по умолчанию. Меняйте поля ниже, только если бот путает тип запроса.</p>
            </div>
        </div>
    </td></tr>
    <tr class="draxter-intent-section"><td colspan="2"><strong>1. Приветствия и вопросы о боте</strong></td></tr>
    <tr><td>Когда клиент пишет</td><td>
        <span class="draxter-intent-effect">Бот отвечает о себе (1–3 предложения), без товаров, цен и просьбы телефона</span>
        <p class="draxter-field-hint">Примеры: «привет», «кто ты», «чем можешь помочь», «что умеешь».</p>
        <label for="INTENT_SMALL_TALK_REGEX"><strong>Шаблон распознавания</strong></label><br>
        <input type="text" id="INTENT_SMALL_TALK_REGEX" name="INTENT_SMALL_TALK_REGEX" value="<?= draxter_aichat_opt('INTENT_SMALL_TALK_REGEX') ?>" class="draxter-aichat-opt-textarea" style="margin-top:4px">
        <p class="draxter-field-hint">Если приветствия уже работают — не меняйте.</p>
    </td></tr>
    <tr class="draxter-intent-section"><td colspan="2"><strong>2. Клиент готов купить</strong></td></tr>
    <tr><td>Когда клиент пишет</td><td>
        <span class="draxter-intent-effect">Бот просит номер телефона для оформления — даже если это первый содержательный вопрос</span>
        <p class="draxter-field-hint">Примеры: «хочу купить», «готов оформить», «беру», «заказать».</p>
        <label for="INTENT_PURCHASE_INTENT_REGEX"><strong>Шаблон распознавания</strong></label><br>
        <input type="text" id="INTENT_PURCHASE_INTENT_REGEX" name="INTENT_PURCHASE_INTENT_REGEX" value="<?= draxter_aichat_opt('INTENT_PURCHASE_INTENT_REGEX') ?>" class="draxter-aichat-opt-textarea" style="margin-top:4px">
        <p class="draxter-field-hint">Текст просьбы телефона настраивается на вкладке «CRM» (поля CTA).</p>
    </td></tr>
    <tr class="draxter-intent-section"><td colspan="2"><strong>3. Поиск товаров в каталоге</strong></td></tr>
    <tr><td>Тематические слова</td><td>
        <span class="draxter-intent-effect">Помогают понять категорию или направление ассортимента, если клиент написал коротко или неоднозначно</span>
        <input type="text" name="INTENT_TOPIC_WORDS" value="<?= draxter_aichat_opt('INTENT_TOPIC_WORDS') ?>" class="draxter-aichat-opt-textarea" placeholder="ваша категория, синоним, бренд, тип товара" style="margin-top:4px">
        <p class="draxter-field-hint">Слова через запятую. Пусто = модуль сам подберёт список по содержимому каталога (вкладка «Каталог»).</p>
    </td></tr>
    <tr class="draxter-intent-section"><td colspan="2"><strong>4. Уточнение поиска</strong> <span style="font-weight:normal;color:#666">— необязательно, для сложных каталогов</span></td></tr>
    <tr><td>Узкая категория в запросе</td><td>
        <span class="draxter-intent-effect">Если клиент спрашивает одним словом или жаргоном, а в каталоге поиск идёт по другому термину — модуль распознаёт такие фразы и сужает подбор</span>
        <p class="draxter-field-hint">Примеры ситуаций: клиент пишет сокращение или разговорное название; в каталоге несколько похожих групп товаров и бот их путает. Если такой проблемы нет — оставьте пустым.</p>
        <label for="INTENT_MOTORCYCLE_QUERY_REGEX"><strong>Шаблон распознавания</strong></label><br>
        <input type="text" id="INTENT_MOTORCYCLE_QUERY_REGEX" name="INTENT_MOTORCYCLE_QUERY_REGEX" value="<?= draxter_aichat_opt('INTENT_MOTORCYCLE_QUERY_REGEX') ?>" class="draxter-aichat-opt-textarea" placeholder="пусто = встроенный шаблон модуля" style="margin-top:4px">
    </td></tr>
    <?php draxter_aichat_tab_help('intent'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('Голос', 'Распознавание речи, озвучка ответов и свои провайдеры'); ?>
    <tr><td>Голосовой ввод</td><td><input type="checkbox" name="VOICE_ENABLED" value="Y" <?= draxter_aichat_opt('VOICE_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Отвечать голосом</td><td><input type="checkbox" name="VOICE_REPLY_ENABLED" value="Y" <?= draxter_aichat_opt('VOICE_REPLY_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Распознавание речи (микрофон)</td><td>
        <select name="VOICE_STT_PROVIDER" id="draxter-voice-stt-select">
            <?php
            $sttProviders = [
                'auto' => 'Авто: Gemini при ключе, иначе браузер',
                'browser' => 'Браузер (Web Speech, лучше Chrome)',
                'gemini' => 'Google Gemini (GEMINI_API_KEY)',
            ];
            $sttPref = $voiceSttPrefRaw;
            if (in_array($sttPref, ['openai_whisper', 'openai', 'yandex'], true)) {
                $sttPref = 'auto';
            }
            foreach ($sttProviders as $p => $label): ?>
                <option value="<?= $p ?>" <?= $sttPref === $p ? 'selected' : '' ?>><?= htmlspecialcharsbx($label) ?></option>
            <?php endforeach;
            foreach ($customVoiceSttProviders as $customStt): ?>
                <option value="<?= htmlspecialcharsbx($customStt['id']) ?>" class="draxter-voice-stt-custom-opt" <?= $sttPref === $customStt['id'] ? 'selected' : '' ?>><?= htmlspecialcharsbx($customStt['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="draxter-field-hint">
            <strong>Авто</strong> — Gemini при ключе на вкладке «AI», иначе браузер.<br>
            <strong>Браузер</strong> — Web Speech API, без ключа; стабильно работает в <strong>Chrome</strong>. В Edge зависит от облака Microsoft и часто недоступен — тогда выберите «Только Gemini» или Chrome.
        </p>
    </td></tr>
    <tr><td>Озвучка ответов (TTS)</td><td>
        <select name="VOICE_TTS_PROVIDER" id="draxter-voice-tts-select">
            <?php
            $ttsPref = $voiceTtsPrefRaw === 'yandex' ? 'browser' : $voiceTtsPrefRaw;
            $ttsProviders = [
                'browser' => 'Браузер (speechSynthesis, без API)',
            ];
            foreach ($ttsProviders as $p => $label): ?>
                <option value="<?= $p ?>" <?= $ttsPref === $p ? 'selected' : '' ?>><?= htmlspecialcharsbx($label) ?></option>
            <?php endforeach;
            foreach ($customVoiceTtsProviders as $customTts): ?>
                <option value="<?= htmlspecialcharsbx($customTts['id']) ?>" class="draxter-voice-tts-custom-opt" <?= $ttsPref === $customTts['id'] ? 'selected' : '' ?>><?= htmlspecialcharsbx($customTts['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </td></tr>
    <tr><td>Gemini STT модель</td><td>
        <input type="text" name="VOICE_GEMINI_STT_MODEL" value="<?= draxter_aichat_opt('VOICE_GEMINI_STT_MODEL') ?>" size="28" placeholder="пусто = GEMINI_MODEL">
        <p class="draxter-field-hint">Для «Авто» / «Google Gemini». Пусто — модель с вкладки «AI».</p>
    </td></tr>
    <tr><td colspan="2"><strong>Свои провайдеры распознавания (STT)</strong></td></tr>
    <tr><td>Настройка STT</td><td>
        <p class="draxter-field-hint" style="margin:0 0 8px">Выберите тип API или укажите свой HTTP-эндпоинт (SaluteSpeech, Tinkoff VoiceKit и др.).</p>
        <div id="draxter-voice-stt-providers"></div>
        <p style="margin:8px 0">
            <button type="button" class="adm-btn" id="draxter-voice-stt-add">+ Добавить STT</button>
        </p>
        <textarea name="VOICE_CUSTOM_STT_PROVIDERS" id="draxter-voice-stt-raw" style="display:none"><?= draxter_aichat_optarea('VOICE_CUSTOM_STT_PROVIDERS', '[]') ?></textarea>
    </td></tr>
    <tr><td colspan="2"><strong>Свои провайдеры озвучки (TTS)</strong></td></tr>
    <tr><td>Настройка TTS</td><td>
        <p class="draxter-field-hint" style="margin:0 0 8px">Свой HTTP API (ответ — аудио) или JSON Speech API (<code>/audio/speech</code>).</p>
        <div id="draxter-voice-tts-providers"></div>
        <p style="margin:8px 0">
            <button type="button" class="adm-btn" id="draxter-voice-tts-add">+ Добавить TTS</button>
        </p>
        <textarea name="VOICE_CUSTOM_TTS_PROVIDERS" id="draxter-voice-tts-raw" style="display:none"><?= draxter_aichat_optarea('VOICE_CUSTOM_TTS_PROVIDERS', '[]') ?></textarea>
    </td></tr>
    <tr><td>Макс. длительность записи (с)</td><td><input type="text" name="VOICE_MAX_SECONDS" value="<?= draxter_aichat_opt('VOICE_MAX_SECONDS', '60') ?>" size="6"></td></tr>
    <tr><td>Макс. символов для TTS</td><td><input type="text" name="VOICE_TTS_MAX_CHARS" value="<?= draxter_aichat_opt('VOICE_TTS_MAX_CHARS', '1500') ?>" size="6"></td></tr>
    <tr><td>Скорость озвучки (браузер)</td><td><input type="text" name="VOICE_TTS_RATE" value="<?= draxter_aichat_opt('VOICE_TTS_RATE', '1.25') ?>" size="6" placeholder="1.25"></td></tr>
    <?php draxter_aichat_tab_help('voice'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('Каталог', 'Источник товаров, профиль подбора и кэш'); ?>
    <?php draxter_aichat_catalog_status(); ?>
    <tr><td>Источник</td><td>
        <select name="CATALOG_SOURCE" id="draxter-catalog-source">
            <option value="yml_url" <?= draxter_aichat_opt('CATALOG_SOURCE', 'yml_url') === 'yml_url' ? 'selected' : '' ?>>YML по URL — файл на сайте по ссылке</option>
            <option value="yml_file" <?= draxter_aichat_opt('CATALOG_SOURCE') === 'yml_file' ? 'selected' : '' ?>>YML файл — путь на диске сервера</option>
            <option value="iblock" <?= draxter_aichat_opt('CATALOG_SOURCE') === 'iblock' ? 'selected' : '' ?>>Инфоблок Bitrix</option>
        </select>
        <p class="draxter-field-hint">Ниже показываются только поля, нужные для выбранного варианта.</p>
    </td></tr>
    <tr class="draxter-catalog-row" data-catalog-for="yml_url">
        <td>Адрес YML (URL)</td>
        <td>
            <input type="text" name="CATALOG_URL" value="<?= draxter_aichat_opt('CATALOG_URL', '/bitrix/catalog_export/catalog.xml') ?>" size="70" placeholder="/bitrix/catalog_export/catalog.xml">
            <p class="draxter-field-hint">Для источника <strong>«YML по URL»</strong>. Относительный путь на этом сайте (как в примере) или полный адрес <code>https://…</code>. Модуль скачивает выгрузку по этому адресу.</p>
        </td>
    </tr>
    <tr class="draxter-catalog-row" data-catalog-for="yml_file">
        <td>Путь к YML на сервере</td>
        <td>
            <input type="text" name="CATALOG_PATH" value="<?= draxter_aichat_opt('CATALOG_PATH') ?>" size="70" placeholder="/var/www/…/bitrix/catalog_export/catalog.xml">
            <p class="draxter-field-hint"><strong>Обязательно</strong> для источника <strong>«YML файл»</strong>. Полный путь к файлу <code>.xml</code> или <code>.yml</code> на диске сервера (не ссылка в браузере). Пример: <code>/var/www/user/data/www/site.ru/bitrix/catalog_export/catalog.xml</code>. Поле «Адрес YML» в этом режиме не используется.</p>
        </td>
    </tr>
    <tr class="draxter-catalog-row" data-catalog-for="iblock">
        <td>ID инфоблока</td>
        <td>
        <input type="text" name="CATALOG_IBLOCK_ID" value="<?= draxter_aichat_opt('CATALOG_IBLOCK_ID') ?>" size="10">
        <?php if (class_exists('CIBlock') && Loader::includeModule('iblock')): ?>
            <select onchange="document.querySelector('[name=CATALOG_IBLOCK_ID]').value=this.value">
                <option value="">— выбрать —</option>
                <?php
                $ibRes = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
                while ($ib = $ibRes->Fetch()):
                    ?>
                    <option value="<?= (int)$ib['ID'] ?>">[<?= (int)$ib['ID'] ?>] <?= htmlspecialcharsbx($ib['NAME']) ?></option>
                <?php endwhile; ?>
            </select>
        <?php endif; ?>
        <p class="draxter-field-hint">Для источника <strong>«Инфоблок Bitrix»</strong> — ID каталога товаров. Поля URL и путь к файлу не нужны.</p>
    </td></tr>
    <tr><td>Профиль каталога</td><td>
        <select name="CATALOG_PROFILE" id="draxter-catalog-profile">
            <option value="auto" <?= draxter_aichat_opt('CATALOG_PROFILE', 'auto') === 'auto' ? 'selected' : '' ?>>Авто — по составу каталога</option>
            <option value="garden" <?= draxter_aichat_opt('CATALOG_PROFILE', 'auto') === 'garden' ? 'selected' : '' ?>>Узкий — ограниченный ассортимент</option>
            <option value="full" <?= draxter_aichat_opt('CATALOG_PROFILE', 'auto') === 'full' ? 'selected' : '' ?>>Полный — весь каталог</option>
        </select>
        <p class="draxter-field-hint">Как бот интерпретирует выгрузку при подборе товаров и ответах «есть / нет в магазине».<br>
            <strong>Авто</strong> — модуль сам смотрит, насколько разнообразен каталог (одна-две группы товаров или много разных), и подбирает режим.<br>
            <strong>Узкий</strong> — для магазинов с небольшой линейкой: бот не выдумывает категории вне фида; на «чужие» запросы отвечает отказом и предлагает то, что реально есть в выгрузке. Чем занимается компания — укажите в поле «Специализация» (вкладка CRM).<br>
            <strong>Полный</strong> — все категории из выгрузки участвуют в поиске; для универсальных интернет-магазинов.</p>
    </td></tr>
    <tr><td>Кэш (мин)</td><td><input type="text" name="CATALOG_REFRESH_MINUTES" value="<?= draxter_aichat_opt('CATALOG_REFRESH_MINUTES', '60') ?>" size="10"></td></tr>
    <tr><td colspan="2"><label><input type="checkbox" name="refresh_catalog" value="Y"> Сбросить кэш при сохранении</label></td></tr>
    <?php draxter_aichat_tab_help('catalog'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php draxter_aichat_tab_header('CRM и сайт', 'Лиды, Bitrix24, почта, Метрика и тексты для клиента'); ?>
    <tr><td>Название магазина</td><td><input type="text" name="SHOP_NAME" value="<?= draxter_aichat_opt('SHOP_NAME', 'Магазин') ?>" size="40"></td></tr>
    <tr><td>Бренд</td><td><input type="text" name="SHOP_BRAND" value="<?= draxter_aichat_opt('SHOP_BRAND') ?>" size="40" placeholder="пусто = название"></td></tr>
    <tr><td>SITE_URL</td><td>
        <input type="text" name="SITE_URL" value="<?= draxter_aichat_opt('SITE_URL') ?>" size="50" placeholder="https://example.com">
        <p class="draxter-field-hint">Адрес сайта с <code>https://</code> — для ссылок на товары в ответах бота и поля «сайт» в лиде CRM. Если пусто, модуль подставит URL из каталога или текущего домена.</p>
    </td></tr>
    <tr><td>Специализация</td><td>
        <input type="text" name="SHOP_SPECIALIZATION" value="<?= draxter_aichat_opt('SHOP_SPECIALIZATION') ?>" size="70" placeholder="Например: продажа сантехники, оптом и в розницу">
        <p class="draxter-field-hint">Кратко, чем занимается магазин — бот использует это в ответах «нет в ассортименте» и при профиле «Узкий» на вкладке «Каталог». Пусто — подставится общая формулировка по названию магазина.</p>
    </td></tr>
    <tr><td>Лимит запросов/мин (IP)</td><td><input type="text" name="RATE_LIMIT_PER_MINUTE" value="<?= draxter_aichat_opt('RATE_LIMIT_PER_MINUTE', '60') ?>" size="6"> (0 = без лимита)</td></tr>
    <tr><td colspan="2">
        <div class="draxter-logs-section" style="margin-top:8px">
            <h4 class="draxter-logs-section-title">Каналы лидов</h4>
            <div class="draxter-logs-card">
                <p class="draxter-logs-hint" style="margin:0">
                    Ниже — два канала. Включите галочкой те, что нужны (можно оба сразу).
                    Ошибка одного канала не мешает другому.
                </p>
            </div>
        </div>
    </td></tr>
    <tr><td colspan="2"><strong>1. Bitrix24 (облако)</strong></td></tr>
    <tr><td>Создавать лиды</td><td><input type="checkbox" name="BITRIX24_LEAD_ENABLED" value="Y" <?= draxter_aichat_opt('BITRIX24_LEAD_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Webhook</td><td><input type="text" name="BITRIX24_WEBHOOK_URL" value="<?= draxter_aichat_opt('BITRIX24_WEBHOOK_URL') ?>" size="70"><br><span class="adm-info-message-wrap">Входящий webhook Bitrix24 (REST). Если поле пустое, модуль может взять URL из <code>config/aichat.config.php</code> на сервере.</span></td></tr>
    <tr><td>Ответственный ID</td><td><input type="text" name="BITRIX24_ASSIGNED_ID" value="<?= draxter_aichat_opt('BITRIX24_ASSIGNED_ID', '1') ?>" size="10"><br><span class="adm-info-message-wrap">ID пользователя Bitrix24, которому назначается лид и контакт.</span></td></tr>
    <tr><td>Название источника</td><td><input type="text" name="BITRIX24_SOURCE_LABEL" value="<?= draxter_aichat_opt('BITRIX24_SOURCE_LABEL', 'aichat') ?>" size="20" placeholder="aichat"><br><span class="adm-info-message-wrap">Как в справочнике Bitrix24: <strong>CRM → Настройки → Справочники → Источники</strong> — колонка «Название». Это же значение показывается в карточке лида в поле «Источник». Модуль сначала ищет источник с таким названием и подставляет его код сам.</span></td></tr>
    <tr><td>Код источника (запасной)</td><td><input type="text" name="BITRIX24_SOURCE_ID" value="<?= draxter_aichat_opt('BITRIX24_SOURCE_ID', 'aichat') ?>" size="20" placeholder="UC_…"><br><span class="adm-info-message-wrap">Внутренний код из того же справочника (<code>WEB</code>, <code>UC_…</code> и т.п.) — колонка «ID» / «Символьный код». Используется только если источника с <strong>названием</strong> из поля выше нет в CRM. Рекомендация: создайте в Bitrix24 источник с нужным названием — тогда запасной код можно не трогать.</span></td></tr>
    <tr><td>Префикс лида</td><td><input type="text" name="CRM_LEAD_TITLE_PREFIX" value="<?= draxter_aichat_opt('CRM_LEAD_TITLE_PREFIX', 'AI-чат') ?>" size="30"></td></tr>
    <tr><td>Маппинг полей B24 (JSON)</td><td>
        <textarea name="CRM_B24_FIELD_MAP" rows="12" cols="70" style="font-family:monospace;font-size:12px"><?= draxter_aichat_optarea('CRM_B24_FIELD_MAP') ?></textarea>
        <p><code>[{"field":"UF_CRM_…","source":"page_url"}, …]</code> — источники: phone, name, comment, page_url, page_title, http_referer, utm_*, session_id, products, site_url, source_label, source_id</p>
        <button type="submit" name="b24_map_template" value="Y" class="adm-btn">Подставить шаблон полей</button>
        <button type="submit" name="b24_load_fields" value="Y" class="adm-btn">Загрузить коды полей из Bitrix24</button>
        <?php if ($b24FieldCodesHint !== ''): ?>
            <p class="adm-info-message-wrap" style="max-width:720px;margin-top:8px"><small><?= htmlspecialcharsbx(mb_substr($b24FieldCodesHint, 0, 2000)) ?></small></p>
        <?php endif; ?>
    </td></tr>
    <tr><td colspan="2"><strong>2. Email</strong></td></tr>
    <tr><td>Отправлять лид на почту</td><td><input type="checkbox" name="CRM_EMAIL_ENABLED" value="Y" <?= draxter_aichat_opt('CRM_EMAIL_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Адреса (через запятую)</td><td><input type="text" name="CRM_EMAIL_TO" value="<?= draxter_aichat_opt('CRM_EMAIL_TO') ?>" size="60" placeholder="manager@example.com"></td></tr>
    <tr><td colspan="2"><strong>Яндекс.Метрика</strong> (цель при успешном создании лида)</td></tr>
    <tr><td>Отправлять цель</td><td><input type="checkbox" name="YANDEX_METRIKA_ENABLED" value="Y" <?= draxter_aichat_opt('YANDEX_METRIKA_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Номер счётчика</td><td><input type="text" name="YANDEX_METRIKA_COUNTER_ID" value="<?= draxter_aichat_opt('YANDEX_METRIKA_COUNTER_ID', '90547846') ?>" size="12" placeholder="90547846"></td></tr>
    <tr><td>Идентификатор цели</td><td><input type="text" name="YANDEX_METRIKA_GOAL" value="<?= draxter_aichat_opt('YANDEX_METRIKA_GOAL', 'aibot') ?>" size="20" placeholder="aibot"> <span class="adm-info-message-wrap">Вызов <code>ym(счётчик,'reachGoal',цель)</code> на сайте, где уже подключена Метрика.</span></td></tr>
    <tr><td colspan="2"><strong>Сообщения CRM</strong></td></tr>
    <tr><td>CTA телефон</td><td><textarea name="MSG_LEAD_PHONE_CTA" rows="2" cols="70"><?= draxter_aichat_optarea('MSG_LEAD_PHONE_CTA') ?></textarea></td></tr>
    <tr><td>CTA в промпте</td><td><textarea name="MSG_LEAD_PHONE_CTA_PROMPT" rows="2" cols="70"><?= draxter_aichat_optarea('MSG_LEAD_PHONE_CTA_PROMPT') ?></textarea></td></tr>
    <tr><td>Ответ при номере OK</td><td><textarea name="MSG_PHONE_REPLY_OK" rows="2" cols="70"><?= draxter_aichat_optarea('MSG_PHONE_REPLY_OK') ?></textarea></td></tr>
    <tr><td>Ответ при номере fallback</td><td><textarea name="MSG_PHONE_REPLY_FALLBACK" rows="2" cols="70"><?= draxter_aichat_optarea('MSG_PHONE_REPLY_FALLBACK') ?></textarea></td></tr>
    <?php draxter_aichat_tab_help('crm'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $statsCloseUrl = $statsUrlBase . '&stats_days=' . (int)$statsDays . '&tabControl_active_tab=edit_stats#edit_stats';
    $statsPeriodUrl = static function (int $days) use ($statsUrlBase): string {
        return $statsUrlBase . '&stats_days=' . $days . '&tabControl_active_tab=edit_stats#edit_stats';
    };
    ?>
    <tr><td colspan="2" style="padding-top:8px">
    <div class="draxter-stats">
        <div class="draxter-stats-toolbar">
            <span class="draxter-stats-toolbar-title">Статистика чатов</span>
            <div class="draxter-stats-periods">
                <a class="draxter-stats-period<?= $statsDays === 7 ? ' is-active' : '' ?>" href="<?= htmlspecialcharsbx($statsPeriodUrl(7)) ?>">7 дней</a>
                <a class="draxter-stats-period<?= $statsDays === 30 ? ' is-active' : '' ?>" href="<?= htmlspecialcharsbx($statsPeriodUrl(30)) ?>">30 дней</a>
            </div>
        </div>

        <?php if ($viewSessionId !== ''): ?>
        <div class="draxter-stats-session" id="draxter-stats-session">
            <div class="draxter-stats-session-head">
                <div>
                    <h3 class="draxter-stats-session-title">Диалог <?= htmlspecialcharsbx($viewSessionId) ?></h3>
                    <?php if ($viewSessionData !== null): ?>
                    <p class="draxter-stats-session-meta">
                        Создан: <?= htmlspecialcharsbx(AdminStats::formatAdminDate((string)($viewSessionData['createdAt'] ?? ''))) ?>
                        · Обновлён: <?= htmlspecialcharsbx(AdminStats::formatAdminDate((string)($viewSessionData['updatedAt'] ?? ''))) ?>
                        <?php if (!empty($viewSessionData['lead']['created'])): ?>
                        · <span class="draxter-stats-badge draxter-stats-badge--lead">Лид</span>
                        <?php endif; ?>
                        <?php if (!empty($viewSessionData['lead']['phone'])): ?>
                        · <?= htmlspecialcharsbx((string)$viewSessionData['lead']['phone']) ?>
                        <?php endif; ?>
                    </p>
                    <?php else: ?>
                    <p class="draxter-stats-session-meta">Сессия не найдена в папке логов.</p>
                    <?php endif; ?>
                </div>
                <a class="draxter-stats-session-close" href="<?= htmlspecialcharsbx($statsCloseUrl) ?>">✕ Закрыть</a>
            </div>
            <?php if ($viewSessionData !== null): ?>
            <div class="draxter-stats-chat">
                <?php foreach (($viewSessionData['turns'] ?? []) as $i => $turn): ?>
                <div class="draxter-stats-turn">
                    <div class="draxter-stats-turn-time"><?= htmlspecialcharsbx(AdminStats::formatAdminDate((string)($turn['at'] ?? ''))) ?> · ход <?= (int)$i + 1 ?></div>
                    <div class="draxter-stats-bubble draxter-stats-bubble--user">
                        <span class="draxter-stats-bubble-label">Клиент</span>
                        <?= draxter_aichat_stats_bubble_text((string)($turn['userMessage'] ?? '')) ?>
                    </div>
                    <div class="draxter-stats-bubble draxter-stats-bubble--bot">
                        <span class="draxter-stats-bubble-label">Бот</span>
                        <?= draxter_aichat_stats_bubble_text((string)($turn['assistantMessage'] ?? '')) ?>
                    </div>
                    <?php if (!empty($turn['error'])): ?>
                    <div class="draxter-stats-turn-error">Ошибка: <?= htmlspecialcharsbx((string)$turn['error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($viewSessionData['turns'])): ?>
                <div class="draxter-stats-empty">В этой сессии пока нет сообщений.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="draxter-stats-kpi">
            <div class="draxter-stats-kpi-card">
                <div class="draxter-stats-kpi-value"><?= (int)$statsData['sessions'] ?></div>
                <div class="draxter-stats-kpi-label">Сессий</div>
            </div>
            <div class="draxter-stats-kpi-card">
                <div class="draxter-stats-kpi-value"><?= (int)$statsData['messages'] ?></div>
                <div class="draxter-stats-kpi-label">Сообщений</div>
            </div>
            <div class="draxter-stats-kpi-card">
                <div class="draxter-stats-kpi-value"><?= (int)$statsData['leads'] ?></div>
                <div class="draxter-stats-kpi-label">Лидов</div>
            </div>
            <div class="draxter-stats-kpi-card<?= (int)$statsData['errors'] > 0 ? ' draxter-stats-kpi-card--warn' : '' ?>">
                <div class="draxter-stats-kpi-value"><?= (int)$statsData['errors'] ?></div>
                <div class="draxter-stats-kpi-label">Ошибок в диалогах</div>
            </div>
        </div>

        <div class="draxter-stats-section">
            <h4 class="draxter-stats-section-title">Топ фраз пользователя</h4>
            <?php if ($statsData['topPhrases'] === []): ?>
                <div class="draxter-stats-empty">Нет данных за выбранный период. Проверьте, что на вкладке «Логи» включено «Логи сессий».</div>
            <?php else: ?>
                <ul class="draxter-stats-phrases">
                    <?php foreach ($statsData['topPhrases'] as $row): ?>
                    <li>
                        <span><?= htmlspecialcharsbx($row['phrase']) ?></span>
                        <span class="draxter-stats-phrases-count"><?= (int)$row['count'] ?>×</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="draxter-stats-section">
            <h4 class="draxter-stats-section-title">Последние диалоги</h4>
            <?php if ($statsData['recent'] === []): ?>
                <div class="draxter-stats-empty">Диалогов за период нет.</div>
            <?php else: ?>
                <div class="draxter-stats-table-wrap">
                    <table class="draxter-stats-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Первый вопрос</th>
                                <th>Ходов</th>
                                <th>Статус</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statsData['recent'] as $row): ?>
                            <?php
                            $sessionLogUrl = $statsUrlBase
                                . '&stats_days=' . (int)$statsDays
                                . '&view_session=' . urlencode($row['sessionId'])
                                . '&tabControl_active_tab=edit_stats#edit_stats';
                            ?>
                            <tr>
                                <td><?= htmlspecialcharsbx(AdminStats::formatAdminDate($row['date'])) ?></td>
                                <td class="draxter-stats-preview">
                                    <?php if (($row['preview'] ?? '') !== ''): ?>
                                        <?= htmlspecialcharsbx($row['preview']) ?>
                                    <?php else: ?>
                                        <code style="font-size:11px"><?= htmlspecialcharsbx($row['sessionId']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$row['turns'] ?></td>
                                <td>
                                    <?php if (!empty($row['lead'])): ?>
                                        <span class="draxter-stats-badge draxter-stats-badge--lead">Лид</span>
                                    <?php elseif (!empty($row['error'])): ?>
                                        <span class="draxter-stats-badge draxter-stats-badge--error">Ошибка</span>
                                    <?php else: ?>
                                        <span class="draxter-stats-badge draxter-stats-badge--ok">OK</span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['phone'])): ?>
                                        <div style="margin-top:4px;font-size:11px;color:#666"><?= htmlspecialcharsbx($row['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><a class="draxter-stats-btn" href="<?= htmlspecialcharsbx($sessionLogUrl) ?>">Открыть лог</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php draxter_aichat_tab_help('stats'); ?>
    </td></tr>
    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $logErrorCount = 0;
    foreach ($logRows as $logRow) {
        if ((int)$logRow['httpCode'] >= 400) {
            $logErrorCount++;
        }
    }
    ?>
    <tr><td colspan="2" style="padding-top:8px">
    <div class="draxter-logs">
        <div class="draxter-logs-section">
            <h4 class="draxter-logs-section-title">Запись диалогов</h4>
            <div class="draxter-logs-card">
                <div class="draxter-logs-settings-row">
                    <label>
                        <input type="checkbox" name="CHAT_LOG_ENABLED" value="Y" <?= draxter_aichat_opt('CHAT_LOG_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>>
                        Включить логи сессий (нужно для вкладки «Статистика»)
                    </label>
                </div>
                <div class="draxter-logs-settings-row">
                    <label for="draxter-chat-log-dir">Папка логов на сервере</label>
                    <input type="text" id="draxter-chat-log-dir" name="CHAT_LOG_DIR" value="<?= draxter_aichat_opt('CHAT_LOG_DIR') ?>" placeholder="/upload/aichat_logs">
                    <p class="draxter-logs-hint">Если поле пустое — каталог по умолчанию <code>/upload/aichat_logs</code>. Здесь же хранятся файлы <code>api-YYYY-MM-DD.log</code>.</p>
                    <span class="draxter-logs-path"><?= htmlspecialcharsbx($chatLogDir) ?></span>
                </div>
                <p class="draxter-logs-hint" style="margin-top:12px">
                    Полные диалоги с клиентами смотрите на вкладке
                    <a href="<?= htmlspecialcharsbx($statsUrlBase . '&tabControl_active_tab=edit_stats#edit_stats') ?>">«Статистика»</a>
                    → «Открыть лог».
                </p>
            </div>
        </div>

        <div class="draxter-logs-section">
            <h4 class="draxter-logs-section-title">Ошибки API и сервисов</h4>
            <?php if ($logFiles === []): ?>
                <div class="draxter-stats-empty">Файлов <code>api-*.log</code> пока нет. Они появятся после первой ошибки Gemini, CRM, голоса или чата.</div>
            <?php else: ?>
                <div class="draxter-logs-toolbar">
                    <span class="draxter-stats-toolbar-title">Файл лога</span>
                    <div class="draxter-stats-periods">
                        <?php foreach (array_slice($logFiles, 0, 14) as $f): ?>
                        <?php
                        $fname = basename($f);
                        $isActive = $logFile === $f;
                        $fileUrl = draxter_aichat_logs_url($logsUrlBase, $f, $logFilter);
                        ?>
                        <a class="draxter-stats-period<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialcharsbx($fileUrl) ?>" title="<?= htmlspecialcharsbx($f) ?>"><?= htmlspecialcharsbx($fname) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <form method="get" class="draxter-logs-filter-form" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
                    <input type="hidden" name="mid" value="<?= htmlspecialcharsbx($moduleId) ?>">
                    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
                    <input type="hidden" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>">
                    <input type="hidden" name="log_file" value="<?= htmlspecialcharsbx($logFile) ?>">
                    <input type="hidden" name="tabControl_active_tab" value="edit_logs">
                    <input type="text" name="log_filter" value="<?= htmlspecialcharsbx($logFilter) ?>" placeholder="Поиск: gemini, 429, quota, crm…">
                    <button type="submit" class="draxter-stats-btn">Найти</button>
                    <?php if ($logFilter !== ''): ?>
                    <a class="draxter-stats-btn" href="<?= htmlspecialcharsbx(draxter_aichat_logs_url($logsUrlBase, $logFile)) ?>">Сбросить</a>
                    <?php endif; ?>
                </form>

                <div class="draxter-logs-quick-filters">
                    <?php
                    $quickFilters = [
                        '' => 'Все',
                        '429' => 'Лимит 429',
                        'quota' => 'Квота Gemini',
                        'gemini' => 'Gemini',
                        'crm' => 'CRM',
                        'transcribe' => 'Голос STT',
                        'chat.stream' => 'Потоковый чат',
                    ];
                    foreach ($quickFilters as $qf => $qlabel):
                        $qUrl = draxter_aichat_logs_url($logsUrlBase, $logFile, $qf);
                    ?>
                    <a class="draxter-logs-chip<?= $logFilter === $qf ? ' is-active' : '' ?>" href="<?= htmlspecialcharsbx($qUrl) ?>"><?= htmlspecialcharsbx($qlabel) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="draxter-logs-meta">
                    Показано <?= count($logRows) ?> строк
                    <?php if ($logFilter !== ''): ?>(фильтр «<?= htmlspecialcharsbx($logFilter) ?>»)<?php endif; ?>
                    · ошибок HTTP ≥ 400: <strong><?= (int)$logErrorCount ?></strong>
                    · файл: <code><?= htmlspecialcharsbx(basename($logFile)) ?></code>
                </div>

                <?php if ($logRows === []): ?>
                    <div class="draxter-stats-empty">В этом файле нет строк<?= $logFilter !== '' ? ' по выбранному фильтру' : '' ?>.</div>
                <?php else: ?>
                    <div class="draxter-stats-table-wrap">
                        <table class="draxter-stats-table">
                            <thead>
                                <tr>
                                    <th title="Дата и время на сервере">Время</th>
                                    <th title="Сервис или провайдер API">Сервис</th>
                                    <th title="Что выполнялось в момент ошибки">Операция</th>
                                    <th title="HTTP-код ответа">Код</th>
                                    <th title="Краткая расшифровка типа ошибки">Тип ошибки</th>
                                    <th title="Текст ответа API или пояснение">Подробности</th>
                                    <th title="Сессия чата, если была передана">Сессия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logRows as $logRow): ?>
                                <tr class="<?= htmlspecialcharsbx($logRow['rowClass']) ?>">
                                    <td style="white-space:nowrap"><?= htmlspecialcharsbx($logRow['time']) ?></td>
                                    <td><?= htmlspecialcharsbx($logRow['providerLabel']) ?></td>
                                    <td>
                                        <span class="draxter-logs-action"><?= htmlspecialcharsbx($logRow['actionLabel']) ?></span>
                                        <?php if ($logRow['actionLabel'] !== $logRow['action']): ?>
                                        <code><?= htmlspecialcharsbx($logRow['action']) ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="draxter-logs-code <?= htmlspecialcharsbx($logRow['httpClass']) ?>" title="<?= htmlspecialcharsbx($logRow['errorType']) ?>">
                                            <?= (int)$logRow['httpCode'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="draxter-logs-error-type<?= $logRow['httpCode'] === 429 ? ' draxter-logs-error-type--quota' : '' ?>">
                                            <?= htmlspecialcharsbx($logRow['errorType']) ?>
                                        </span>
                                    </td>
                                    <td class="draxter-logs-message">
                                        <?php if ($logRow['detail'] !== ''): ?>
                                        <div class="draxter-logs-detail"><?= htmlspecialcharsbx($logRow['detail']) ?></div>
                                        <?php else: ?>
                                        <div class="draxter-logs-detail is-empty"><?= htmlspecialcharsbx(ApiErrorLog::httpCodeSummary((int)$logRow['httpCode'], (string)$logRow['provider'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($logRow['sessionId'] !== ''): ?>
                                        <a class="draxter-stats-btn" href="<?= htmlspecialcharsbx($statsUrlBase . '&stats_days=30&view_session=' . urlencode($logRow['sessionId']) . '&tabControl_active_tab=edit_stats#edit_stats') ?>">Диалог</a>
                                        <?php else: ?>
                                        <span style="color:#999">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="draxter-logs-purge">
                    <strong>Очистка старых api-логов</strong>
                    <p class="draxter-logs-hint">Удаляет файлы <code>api-*.log</code> старше указанного числа дней. Диалоги сессий (<code>*.json</code>) не трогаются.</p>
                    <form method="post" class="draxter-logs-purge-form" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>&tabControl_active_tab=edit_logs#edit_logs" onsubmit="return confirm('Удалить старые api-*.log?');">
                        <?= bitrix_sessid_post() ?>
                        <input type="hidden" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>">
                        <input type="hidden" name="purge_api_logs" value="Y">
                        <label>Удалить старше</label>
                        <input type="number" name="purge_days" value="30" min="1">
                        <span>дней</span>
                        <button type="submit" class="draxter-stats-btn">Очистить api-логи</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php draxter_aichat_tab_help('logs'); ?>
    </td></tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <input type="submit" name="reset_defaults" value="Сбросить к умолчанию" class="adm-btn" onclick="return confirm('Сбросить все настройки этого сайта?');">
    <?php $tabControl->End(); ?>
</form>

<div class="draxter-admin-import">
<div class="adm-info-message-wrap">
    <div class="adm-info-message draxter-logs-card">
        <strong>Импорт из <code>aichat.config.php</code></strong> — только для переноса старых настроек из файла в базу (один раз при миграции).
        В обычной работе всё редактируется в полях выше; файл <code><?= htmlspecialcharsbx($configPath) ?></code> используется как запасной источник, если поле в админке пустое.
        <form method="post" style="margin-top:10px" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>">
            <label><input type="checkbox" name="import_from_config" value="Y"> Скопировать значения из файла в базу</label>
            <label><input type="checkbox" name="refresh_catalog" value="Y"> + сбросить кэш каталога</label>
            <input type="submit" value="Импорт" class="adm-btn">
        </form>
    </div>
</div>
</div>

<script>
(function () {
    function bindColorPicker(pickerId, textId) {
        var picker = document.getElementById(pickerId);
        var text = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', function () {
            text.value = picker.value;
        });
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                picker.value = text.value;
            }
        });
    }
    bindColorPicker('drax-accent-picker', 'drax-accent-text');
    bindColorPicker('drax-fab-picker', 'drax-fab-text');
})();
</script>

<script>
(function () {
    var rawEl = document.getElementById('draxter-ai-providers-raw');
    var blocksEl = document.getElementById('draxter-ai-providers');
    var providerSelect = document.getElementById('draxter-ai-provider-select');
    if (!rawEl || !blocksEl) return;

    function escapeAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function escapeText(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
    }

    function parseExtraHeaders(value) {
        if (!value || !String(value).trim()) {
            return {};
        }
        try {
            var parsed = JSON.parse(String(value));
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function extraHeadersToText(headers) {
        if (!headers || typeof headers !== 'object' || Array.isArray(headers)) {
            return '';
        }
        var keys = Object.keys(headers);
        if (!keys.length) {
            return '';
        }
        try {
            return JSON.stringify(headers, null, 2);
        } catch (e) {
            return '';
        }
    }

    function parseRaw(raw) {
        if (!raw || !String(raw).trim()) {
            return [];
        }
        try {
            var data = JSON.parse(String(raw));
            return Array.isArray(data) ? data : [];
        } catch (e) {
            return [];
        }
    }

    function syncProviderSelect() {
        if (!providerSelect) {
            return;
        }
        var selected = providerSelect.value;
        providerSelect.querySelectorAll('.draxter-ai-provider-custom-opt').forEach(function (opt) {
            opt.remove();
        });
        blocksEl.querySelectorAll('.draxter-ai-provider-block').forEach(function (el) {
            var id = (el.querySelector('.draxter-ai-provider-id') || {}).value || '';
            var name = (el.querySelector('.draxter-ai-provider-name') || {}).value || id;
            id = String(id).trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[-_]+|[-_]+$/g, '');
            if (!id || id === 'gemini' || id === 'deepseek' || id === 'perplexity' || id === 'openai') {
                return;
            }
            var opt = document.createElement('option');
            opt.value = id;
            opt.textContent = name.trim() || id;
            opt.className = 'draxter-ai-provider-custom-opt';
            if (selected === id) {
                opt.selected = true;
            }
            providerSelect.appendChild(opt);
        });
    }

    function syncRaw() {
        var rows = [];
        blocksEl.querySelectorAll('.draxter-ai-provider-block').forEach(function (el) {
            var id = String((el.querySelector('.draxter-ai-provider-id') || {}).value || '')
                .trim()
                .toLowerCase()
                .replace(/[^a-z0-9_-]+/g, '-')
                .replace(/^[-_]+|[-_]+$/g, '');
            var name = String((el.querySelector('.draxter-ai-provider-name') || {}).value || '').trim();
            var url = String((el.querySelector('.draxter-ai-provider-url') || {}).value || '').trim();
            var apiKey = String((el.querySelector('.draxter-ai-provider-key') || {}).value || '').trim();
            var model = String((el.querySelector('.draxter-ai-provider-model') || {}).value || '').trim();
            var auth = String((el.querySelector('.draxter-ai-provider-auth') || {}).value || 'bearer').trim();
            var extraHeaders = parseExtraHeaders((el.querySelector('.draxter-ai-provider-headers') || {}).value);
            if (!id && !name && !url && !apiKey && !model) {
                return;
            }
            if (!id || !url || !model) {
                return;
            }
            rows.push({
                id: id,
                name: name || id,
                url: url,
                api_key: apiKey,
                model: model,
                auth: auth || 'bearer',
                extra_headers: extraHeaders
            });
        });
        rawEl.value = rows.length ? JSON.stringify(rows, null, 2) : '[]';
        syncProviderSelect();
    }

    function renumberBlocks() {
        blocksEl.querySelectorAll('.draxter-ai-provider-block').forEach(function (el, idx) {
            var head = el.querySelector('.draxter-ai-provider-block-num');
            if (head) {
                head.textContent = 'Провайдер ' + (idx + 1);
            }
        });
    }

    function bindBlock(el) {
        el.querySelectorAll('input, textarea, select').forEach(function (input) {
            input.addEventListener('input', syncRaw);
            input.addEventListener('change', syncRaw);
        });
        var removeBtn = el.querySelector('.draxter-ai-provider-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                var id = String((el.querySelector('.draxter-ai-provider-id') || {}).value || '').trim();
                if (providerSelect && providerSelect.value === id) {
                    providerSelect.value = 'gemini';
                }
                el.remove();
                renumberBlocks();
                syncRaw();
            });
        }
    }

    function addBlock(data) {
        data = data || {};
        var extraHeadersText = extraHeadersToText(data.extra_headers || data.extraHeaders || {});
        var el = document.createElement('div');
        el.className = 'draxter-ai-provider-block';
        el.innerHTML =
            '<div class="draxter-ai-provider-block-head">' +
            '<strong class="draxter-ai-provider-block-num">Провайдер</strong>' +
            '<button type="button" class="draxter-ai-provider-remove">Удалить</button>' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Код (латиница, без пробелов)</label>' +
            '<p class="draxter-field-hint">Внутренний идентификатор, например <code>mistral</code> или <code>openrouter</code></p>' +
            '<input type="text" class="draxter-ai-provider-id" value="' + escapeAttr(data.id || '') + '" placeholder="mistral" autocomplete="off">' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Название в списке</label>' +
            '<input type="text" class="draxter-ai-provider-name" value="' + escapeAttr(data.name || '') + '" placeholder="Mistral AI" autocomplete="off">' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Адрес API (полная ссылка из документации)</label>' +
            '<p class="draxter-field-hint">Не адрес сайта и не эта страница админки — только URL из кабинета нейросети</p>' +
            '<input type="url" class="draxter-ai-provider-url" value="' + escapeAttr(data.url || '') + '" placeholder="https://api.mistral.ai/v1/chat/completions" autocomplete="off" spellcheck="false">' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Секретный ключ API</label>' +
            '<input type="password" class="draxter-ai-provider-key" value="' + escapeAttr(data.api_key || data.apiKey || '') + '" placeholder="ключ из личного кабинета сервиса" autocomplete="off">' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Имя модели</label>' +
            '<p class="draxter-field-hint">Точное название из документации провайдера</p>' +
            '<input type="text" class="draxter-ai-provider-model" value="' + escapeAttr(data.model || '') + '" placeholder="например mistral-small-latest" autocomplete="off">' +
            '</div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Как передавать ключ</label>' +
            '<select class="draxter-ai-provider-auth">' +
            '<option value="bearer"' + ((data.auth || 'bearer') === 'bearer' ? ' selected' : '') + '>Ключ в заголовке Authorization (обычно)</option>' +
            '<option value="api-key"' + (data.auth === 'api-key' ? ' selected' : '') + '>Ключ в заголовке x-api-key</option>' +
            '<option value="none"' + (data.auth === 'none' ? ' selected' : '') + '>Без ключа в заголовке (редко)</option>' +
            '</select></div>' +
            '<div class="draxter-ai-provider-field">' +
            '<label class="draxter-ai-provider-field-label">Дополнительные заголовки (JSON, необязательно)</label>' +
            '<p class="draxter-field-hint">Для OpenRouter часто нужны HTTP-Referer и X-Title — укажите адрес вашего сайта</p>' +
            '<textarea class="draxter-ai-provider-headers draxter-aichat-opt-textarea" rows="3" placeholder=\'{"HTTP-Referer":"https://ваш-сайт.ru","X-Title":"Чат на сайте"}\'>' + escapeText(extraHeadersText) + '</textarea>' +
            '</div>';
        blocksEl.appendChild(el);
        bindBlock(el);
        renumberBlocks();
        syncRaw();
    }

    parseRaw(rawEl.value).forEach(addBlock);

    var PRESETS = {
        mistral: {
            id: 'mistral',
            name: 'Mistral AI',
            url: 'https://api.mistral.ai/v1/chat/completions',
            model: 'mistral-small-latest',
            auth: 'bearer'
        },
        openrouter: {
            id: 'openrouter',
            name: 'OpenRouter',
            url: 'https://openrouter.ai/api/v1/chat/completions',
            model: 'openai/gpt-4o-mini',
            auth: 'bearer',
            extra_headers: {
                'HTTP-Referer': 'https://ваш-сайт.ru',
                'X-Title': 'Чат на сайте'
            }
        }
    };

    function bindPreset(btnId, key) {
        var btn = document.getElementById(btnId);
        if (!btn || !PRESETS[key]) {
            return;
        }
        btn.addEventListener('click', function () {
            addBlock(PRESETS[key]);
        });
    }
    bindPreset('draxter-ai-provider-preset-mistral', 'mistral');
    bindPreset('draxter-ai-provider-preset-openrouter', 'openrouter');

    var addBtn = document.getElementById('draxter-ai-provider-add');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            addBlock({});
        });
    }

    var mainForm = rawEl.closest('form');
    if (mainForm) {
        mainForm.addEventListener('submit', syncRaw);
    }

    window.draxterAiProvidersSyncRaw = syncRaw;
})();
</script>

<script>
(function () {
    function escapeAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function escapeText(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
    }
    function slugify(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^[-_]+|[-_]+$/g, '');
    }

    function initVoiceProviders(config) {
        var rawEl = document.getElementById(config.rawId);
        var blocksEl = document.getElementById(config.blocksId);
        var selectEl = document.getElementById(config.selectId);
        var customClass = config.customOptClass;
        var reserved = config.reserved || [];
        if (!rawEl || !blocksEl) return;

        function parseRaw(raw) {
            if (!raw || !String(raw).trim()) return [];
            try {
                var data = JSON.parse(String(raw));
                return Array.isArray(data) ? data : [];
            } catch (e) {
                return [];
            }
        }

        function syncSelect() {
            if (!selectEl) return;
            var selected = selectEl.value;
            selectEl.querySelectorAll('.' + customClass).forEach(function (opt) {
                opt.remove();
            });
            blocksEl.querySelectorAll('.draxter-voice-provider-block').forEach(function (el) {
                var id = slugify((el.querySelector('.draxter-voice-id') || {}).value || '');
                var name = String((el.querySelector('.draxter-voice-name') || {}).value || id).trim();
                if (!id || reserved.indexOf(id) >= 0) return;
                var opt = document.createElement('option');
                opt.value = id;
                opt.textContent = name || id;
                opt.className = customClass;
                if (selected === id) opt.selected = true;
                selectEl.appendChild(opt);
            });
        }

        function syncRaw() {
            var rows = [];
            blocksEl.querySelectorAll('.draxter-voice-provider-block').forEach(function (el) {
                var idInput = el.querySelector('.draxter-voice-id');
                var storedId = slugify((idInput || {}).value || '');
                var name = String((el.querySelector('.draxter-voice-name') || {}).value || '').trim();
                var id = storedId || slugify(name);
                if (idInput && !storedId && id) {
                    idInput.value = id;
                }
                var kind = String((el.querySelector('.draxter-voice-kind') || {}).value || '').trim();
                var url = String((el.querySelector('.draxter-voice-url') || {}).value || '').trim();
                var apiKey = String((el.querySelector('.draxter-voice-key') || {}).value || '').trim();
                var model = String((el.querySelector('.draxter-voice-model') || {}).value || '').trim();
                var voice = String((el.querySelector('.draxter-voice-voice') || {}).value || '').trim();
                var lang = String((el.querySelector('.draxter-voice-lang') || {}).value || 'ru-RU').trim();
                var auth = String((el.querySelector('.draxter-voice-auth') || {}).value || 'api-key').trim();
                if (!name && !url && !apiKey) return;
                if (!id || !kind || !name) return;
                rows.push({
                    id: id,
                    name: name,
                    kind: kind,
                    url: url,
                    api_key: apiKey,
                    model: model,
                    voice: voice,
                    folder_id: '',
                    lang: lang || 'ru-RU',
                    auth: auth || 'api-key',
                    extra_headers: {}
                });
            });
            rawEl.value = rows.length ? JSON.stringify(rows, null, 2) : '[]';
            syncSelect();
        }

        function updateFieldVisibility(el) {
            var kind = String((el.querySelector('.draxter-voice-kind') || {}).value || '').trim();
            var isHttpStt = config.mode === 'stt' && kind === 'http';
            var isGeminiStt = config.mode === 'stt' && kind === 'gemini';
            var isHttpTts = config.mode === 'tts' && kind === 'http_audio';
            var isSpeechJson = config.mode === 'tts' && kind === 'speech_json';

            function show(sel, visible) {
                var node = el.querySelector(sel);
                if (node && node.closest('.draxter-ai-provider-field')) {
                    node.closest('.draxter-ai-provider-field').style.display = visible ? '' : 'none';
                }
            }

            show('.draxter-voice-url', isHttpStt || isHttpTts || isSpeechJson);
            show('.draxter-voice-key', true);
            show('.draxter-voice-model', isGeminiStt || isHttpStt || isHttpTts || isSpeechJson);
            show('.draxter-voice-voice', isHttpTts || isSpeechJson);
            show('.draxter-voice-lang', isHttpStt || isHttpTts);
            show('.draxter-voice-auth', isHttpStt || isHttpTts || isSpeechJson);
        }

        function kindOptions(selected) {
            return config.kinds
                .map(function (k) {
                    return (
                        '<option value="' +
                        k.value +
                        '"' +
                        (selected === k.value ? ' selected' : '') +
                        '>' +
                        k.label +
                        '</option>'
                    );
                })
                .join('');
        }

        function addBlock(data) {
            data = data || {};
            var el = document.createElement('div');
            el.className = 'draxter-voice-provider-block draxter-ai-provider-block';
            el.innerHTML =
                '<input type="hidden" class="draxter-voice-id" value="' +
                escapeAttr(data.id || '') +
                '">' +
                '<div class="draxter-ai-provider-block-head"><strong>Провайдер</strong><button type="button" class="draxter-voice-remove">Удалить</button></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Название</label><p class="draxter-field-hint">Как будет в списке «Распознавание» / «Озвучка»</p><input type="text" class="draxter-voice-name" value="' +
                escapeAttr(data.name || '') +
                '" placeholder="SaluteSpeech"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Тип API</label><select class="draxter-voice-kind">' +
                kindOptions(data.kind || config.kinds[0].value) +
                '</select></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">URL API</label><p class="draxter-field-hint">Полный адрес из документации сервиса</p><input type="url" class="draxter-voice-url" value="' +
                escapeAttr(data.url || '') +
                '" placeholder="https://…"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">API-ключ</label><input type="password" class="draxter-voice-key" value="' +
                escapeAttr(data.api_key || '') +
                '" autocomplete="off"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Модель</label><input type="text" class="draxter-voice-model" value="' +
                escapeAttr(data.model || '') +
                '" placeholder="gemini-2.5-flash"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Голос</label><input type="text" class="draxter-voice-voice" value="' +
                escapeAttr(data.voice || '') +
                '" placeholder="jane"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Язык</label><input type="text" class="draxter-voice-lang" value="' +
                escapeAttr(data.lang || 'ru-RU') +
                '"></div>' +
                '<div class="draxter-ai-provider-field"><label class="draxter-ai-provider-field-label">Передача ключа</label><select class="draxter-voice-auth">' +
                '<option value="api-key"' +
                ((data.auth || 'api-key') === 'api-key' ? ' selected' : '') +
                '>Api-Key</option><option value="bearer"' +
                (data.auth === 'bearer' ? ' selected' : '') +
                '>Bearer</option><option value="none"' +
                (data.auth === 'none' ? ' selected' : '') +
                '>Без ключа в заголовке</option></select></div>';
            blocksEl.appendChild(el);
            el.querySelectorAll('input, textarea, select').forEach(function (input) {
                input.addEventListener('input', function () {
                    updateFieldVisibility(el);
                    syncRaw();
                });
                input.addEventListener('change', function () {
                    updateFieldVisibility(el);
                    syncRaw();
                });
            });
            var removeBtn = el.querySelector('.draxter-voice-remove');
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    el.remove();
                    syncRaw();
                });
            }
            updateFieldVisibility(el);
            syncRaw();
        }

        parseRaw(rawEl.value).forEach(addBlock);

        var addBtn = document.getElementById(config.addBtnId);
        if (addBtn) addBtn.addEventListener('click', function () { addBlock({}); });

        var presetBtn = document.getElementById(config.presetBtnId);
        if (presetBtn && config.preset) {
            presetBtn.addEventListener('click', function () {
                addBlock(config.preset);
            });
        }

        var mainForm = rawEl.closest('form');
        if (mainForm) mainForm.addEventListener('submit', syncRaw);
    }

    initVoiceProviders({
        mode: 'stt',
        rawId: 'draxter-voice-stt-raw',
        blocksId: 'draxter-voice-stt-providers',
        selectId: 'draxter-voice-stt-select',
        customOptClass: 'draxter-voice-stt-custom-opt',
        addBtnId: 'draxter-voice-stt-add',
        reserved: ['auto', 'browser', 'gemini', 'openai', 'openai_whisper'],
        kinds: [
            { value: 'gemini', label: 'Google Gemini (мультимодальное API)' },
            { value: 'http', label: 'Свой HTTP API (загрузка аудио)' }
        ]
    });

    initVoiceProviders({
        mode: 'tts',
        rawId: 'draxter-voice-tts-raw',
        blocksId: 'draxter-voice-tts-providers',
        selectId: 'draxter-voice-tts-select',
        customOptClass: 'draxter-voice-tts-custom-opt',
        addBtnId: 'draxter-voice-tts-add',
        reserved: ['browser'],
        kinds: [
            { value: 'http_audio', label: 'Свой HTTP API (ответ — аудиофайл)' },
            { value: 'speech_json', label: 'JSON Speech API (/audio/speech)' }
        ]
    });

    (function initCatalogSourceFields() {
        var sel = document.getElementById('draxter-catalog-source');
        if (!sel) return;
        function sync() {
            var v = sel.value;
            document.querySelectorAll('.draxter-catalog-row[data-catalog-for]').forEach(function (row) {
                row.style.display = row.getAttribute('data-catalog-for') === v ? '' : 'none';
            });
        }
        sel.addEventListener('change', sync);
        sync();
    })();
})();
</script>

<script>
(function () {
    var rawEl = document.getElementById('draxter-prompt-blocks-raw');
    var blocksEl = document.getElementById('draxter-prompt-blocks');
    if (!rawEl || !blocksEl) return;

    var MODE_LABELS = {
        always: 'Всегда',
        catalog: 'С каталогом',
        small_talk: 'Приветствия',
        purchase: 'Клиент готов купить',
        phrase: 'По вопросу клиента'
    };

    function parseRaw(raw) {
        var sections = [];
        var lines = String(raw || '').replace(/\r/g, '').split('\n');
        var current = null;

        function flush() {
            if (!current) return;
            if ((current.body && current.body.trim()) || current.title || current.phrase) {
                sections.push({
                    title: current.title || '',
                    mode: normalizeMode(current.mode || 'always'),
                    phrase: current.phrase || '',
                    body: (current.body || '').trim()
                });
            }
            current = null;
        }

        lines.forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed || trimmed === '---') {
                flush();
                return;
            }
            var m;
            if ((m = trimmed.match(/^#\s*(?:название|title)\s*:\s*(.+)$/i))) {
                flush();
                current = { title: m[1].trim(), mode: 'always', phrase: '', body: '' };
                return;
            }
            if ((m = trimmed.match(/^#\s*(?:режим|mode)\s*:\s*(.+)$/i))) {
                if (!current) current = { title: '', mode: 'always', phrase: '', body: '' };
                current.mode = normalizeMode(m[1].trim());
                return;
            }
            if ((m = trimmed.match(/^#\s*(?:вопрос|фраза|предложение|ключевые\s*слова|keywords)\s*:\s*(.+)$/i))) {
                if (!current) current = { title: '', mode: 'phrase', phrase: '', body: '' };
                current.phrase = m[1].trim();
                if (current.mode === 'always') current.mode = 'phrase';
                return;
            }
            if (!current) current = { title: '', mode: 'always', phrase: '', body: '' };
            current.body += (current.body ? '\n' : '') + line;
        });
        flush();
        return sections;
    }

    function normalizeMode(mode) {
        var m = String(mode || '').toLowerCase().trim();
        if (m === 'catalog' || m === 'с каталогом' || m === 'каталог' || m === 'товары') return 'catalog';
        if (m === 'small_talk' || m === 'small talk' || m === 'приветствие' || m === 'приветствия') return 'small_talk';
        if (m === 'purchase' || m === 'покупка' || m === 'купить' || m === 'заказ' || m === 'клиент готов купить') return 'purchase';
        if (m === 'phrase' || m === 'вопрос' || m === 'фраза' || m === 'предложение' || m === 'по вопросу' || m === 'свой вопрос' || m === 'по вопросу клиента') return 'phrase';
        if (m.indexOf('вопрос') !== -1 || m.indexOf('фраз') !== -1) return 'phrase';
        if (m === 'keywords' || m === 'ключевые слова' || m === 'свои ключевые слова' || m === 'по словам') return 'phrase';
        return 'always';
    }

    function modeLabel(mode) {
        return MODE_LABELS[mode] || MODE_LABELS.always;
    }

    function serializeSections(sections) {
        return sections.map(function (s) {
            var lines = [];
            if (s.title) lines.push('# название: ' + s.title);
            if (s.mode && s.mode !== 'always') lines.push('# режим: ' + s.mode);
            if ((s.mode === 'phrase' || s.mode === 'keywords') && s.phrase) lines.push('# вопрос: ' + s.phrase);
            if (s.body) lines.push(s.body);
            return lines.join('\n');
        }).filter(Boolean).join('\n\n');
    }

    function syncRaw() {
        var sections = [];
        blocksEl.querySelectorAll('.draxter-prompt-block').forEach(function (el) {
            sections.push({
                title: (el.querySelector('.draxter-prompt-title') || {}).value || '',
                mode: (el.querySelector('.draxter-prompt-mode') || {}).value || 'always',
                phrase: (el.querySelector('.draxter-prompt-phrase') || {}).value || '',
                body: (el.querySelector('.draxter-prompt-body') || {}).value || ''
            });
        });
        rawEl.value = serializeSections(sections);
    }

    function togglePhraseField(el) {
        var mode = (el.querySelector('.draxter-prompt-mode') || {}).value || 'always';
        var wrap = el.querySelector('.draxter-prompt-phrase-wrap');
        if (wrap) wrap.style.display = (mode === 'phrase' || mode === 'keywords') ? 'block' : 'none';
    }

    function renumberBlocks() {
        blocksEl.querySelectorAll('.draxter-prompt-block').forEach(function (el, idx) {
            var head = el.querySelector('.draxter-prompt-block-num');
            if (head) head.textContent = 'Промпт ' + (idx + 1);
        });
    }

    function bindBlock(el) {
        el.querySelectorAll('input, textarea, select').forEach(function (input) {
            input.addEventListener('input', syncRaw);
            input.addEventListener('change', function () {
                togglePhraseField(el);
                syncRaw();
            });
        });
        var removeBtn = el.querySelector('.draxter-prompt-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                el.remove();
                renumberBlocks();
                syncRaw();
            });
        }
        togglePhraseField(el);
    }

    function escapeAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function escapeText(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
    }

    function addBlock(data) {
        data = data || { title: '', mode: 'always', phrase: '', body: '' };
        var el = document.createElement('div');
        el.className = 'draxter-prompt-block';
        el.innerHTML =
            '<div class="draxter-prompt-block-head">' +
            '<strong class="draxter-prompt-block-num">Промпт</strong>' +
            '<button type="button" class="draxter-prompt-remove">Удалить</button>' +
            '</div>' +
            '<label>Название (для себя)' +
            '<input type="text" class="draxter-prompt-title" value="' + escapeAttr(data.title) + '" placeholder="Гарантия, акции, тон общения">' +
            '</label>' +
            '<label>Когда добавлять в промпт' +
            '<select class="draxter-prompt-mode">' +
            '<option value="always"' + (data.mode === 'always' ? ' selected' : '') + '>Всегда</option>' +
            '<option value="catalog"' + (data.mode === 'catalog' ? ' selected' : '') + '>С каталогом (подбор товаров)</option>' +
            '<option value="small_talk"' + (data.mode === 'small_talk' ? ' selected' : '') + '>Приветствия («привет», «кто ты»)</option>' +
            '<option value="purchase"' + (data.mode === 'purchase' ? ' selected' : '') + '>Клиент готов купить</option>' +
            '<option value="phrase"' + (data.mode === 'phrase' || data.mode === 'keywords' ? ' selected' : '') + '>По вопросу клиента</option>' +
            '</select></label>' +
            '<label class="draxter-prompt-phrase-wrap" style="display:' + (data.mode === 'phrase' || data.mode === 'keywords' ? 'block' : 'none') + '">Пример вопроса клиента' +
            '<input type="text" class="draxter-prompt-phrase" value="' + escapeAttr(data.phrase || data.keywords || '') + '" placeholder="какова длинна стрелы?">' +
            '<span style="display:block;font-size:11px;color:#666;margin-top:4px">Несколько вариантов — с новой строки или через |. Промпт сработает, если вопрос клиента похож на пример.</span>' +
            '</label>' +
            '<label>Текст инструкции' +
            '<textarea class="draxter-prompt-body draxter-aichat-opt-textarea" rows="4" placeholder="Инструкция для нейросети. Можно {shop_name} и {brand}.">' + escapeText(data.body) + '</textarea>' +
            '</label>';
        blocksEl.appendChild(el);
        bindBlock(el);
        renumberBlocks();
        syncRaw();
    }

    var initial = parseRaw(rawEl.value);
    initial.forEach(addBlock);

    var addBtn = document.getElementById('draxter-prompt-add-block');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            addBlock({});
        });
    }

    var mainForm = rawEl.closest('form');
    if (mainForm) {
        mainForm.addEventListener('submit', syncRaw);
    }

    window.draxterPromptSyncRaw = syncRaw;
})();
</script>

<script>
(function () {
    var rawEl = document.getElementById('draxter-faq-knowledge-raw');
    var blocksEl = document.getElementById('draxter-faq-blocks');
    if (!rawEl || !blocksEl) return;

    function stripFaqPlaceholderPrefix(text) {
        return String(text || '')
            .replace(/^\s*ЗАПОЛНИТЕ\s*:\s*/iu, '')
            .replace(/^\s*Заполните\s*:\s*/iu, '')
            .replace(/^\s*заполните\s*:\s*/iu, '')
            .trim();
    }

    var FAQ_TEMPLATES = {
        delivery: {
            title: 'Доставка',
            keywords: 'доставка, доставить, срок, стоимость, город, регион, екатеринбург, екб, транспорт',
            body: 'Доставляем по России. Срок и стоимость зависят от региона — точный расчёт сделает менеджер после заявки или по телефону на сайте.'
        },
        payment: {
            title: 'Оплата',
            keywords: 'оплата, оплатить, при получении, наложенный, рассрочка, карт',
            body: 'Способы оплаты и возможность оплаты при получении — по вашей технике.'
        },
        dealers: {
            title: 'Дилеры и города',
            keywords: 'дилер, официальный, город, салон, где купить, представитель',
            body: 'Города с официальными дилерами или ссылка на страницу дилеров на сайте.'
        },
        clutch: {
            title: 'Сцепление и трансмиссия',
            keywords: 'сцепление, сухое, масляная, ванна, редуктор, муфта',
            body: 'Тип сцепления: сухое или в масляной ванне — по характеристикам ваших моделей.'
        },
        reducer: {
            title: 'Редуктор мини-трактора',
            keywords: 'задний редуктор, редуктор, мини трактор, трактор, модель',
            body: 'От какого агрегата задний редуктор, каталожные модели или артикулы.'
        },
        bucket: {
            title: 'Ковш и стрела',
            keywords: 'ковш, объём, объем, стрела, подъём, подъем, высота, погрузчик, экскаватор',
            body: 'Объём ковша и высота подъёма стрелы по моделям (из паспорта или прайса).'
        }
    };

    function parseRaw(raw) {
        var sections = [];
        var lines = String(raw || '').replace(/\r/g, '').split('\n');
        var current = null;

        function flush() {
            if (!current) return;
            if ((current.body && current.body.trim()) || current.title || (current.keywords && current.keywords.length)) {
                sections.push({
                    title: current.title || '',
                    keywords: (current.keywords || []).join(', '),
                    body: stripFaqPlaceholderPrefix((current.body || '').trim())
                });
            }
            current = null;
        }

        lines.forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed || trimmed === '---') {
                flush();
                return;
            }
            var m;
            if ((m = trimmed.match(/^#\s*(keywords|ключевые\s*слова?|ключевые)\s*:\s*(.+)$/i))) {
                flush();
                current = { keywords: m[2].split(/[,;|]+/).map(function (w) { return w.trim(); }).filter(Boolean), title: '', body: '' };
                return;
            }
            if ((m = trimmed.match(/^#\s*(title|заголовок)\s*:\s*(.+)$/i))) {
                if (!current) current = { keywords: [], title: '', body: '' };
                current.title = m[2].trim();
                return;
            }
            if (!current) current = { keywords: [], title: '', body: '' };
            current.body += (current.body ? '\n' : '') + line;
        });
        flush();
        return sections;
    }

    function serializeSections(sections) {
        return sections.map(function (s) {
            var lines = [];
            if (s.keywords) lines.push('# ключевые слова: ' + s.keywords);
            if (s.title) lines.push('# заголовок: ' + s.title);
            if (s.body) lines.push(s.body);
            return lines.join('\n');
        }).filter(Boolean).join('\n\n');
    }

    function syncRaw() {
        var sections = [];
        blocksEl.querySelectorAll('.draxter-faq-block').forEach(function (el) {
            sections.push({
                keywords: (el.querySelector('.draxter-faq-kw') || {}).value || '',
                title: (el.querySelector('.draxter-faq-title') || {}).value || '',
                body: (el.querySelector('.draxter-faq-body') || {}).value || ''
            });
        });
        rawEl.value = serializeSections(sections);
    }

    function renumberBlocks() {
        blocksEl.querySelectorAll('.draxter-faq-block').forEach(function (el, idx) {
            var head = el.querySelector('.draxter-faq-block-num');
            if (head) head.textContent = 'Блок ' + (idx + 1);
        });
    }

    function bindBlock(el) {
        el.querySelectorAll('input, textarea').forEach(function (input) {
            input.addEventListener('input', syncRaw);
        });
        var removeBtn = el.querySelector('.draxter-faq-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                el.remove();
                renumberBlocks();
                syncRaw();
            });
        }
    }

    function addBlock(data) {
        data = data || { keywords: '', title: '', body: '' };
        var el = document.createElement('div');
        el.className = 'draxter-faq-block';
        el.innerHTML =
            '<div class="draxter-faq-block-head">' +
            '<strong class="draxter-faq-block-num">Блок</strong>' +
            '<button type="button" class="draxter-faq-remove">Удалить</button>' +
            '</div>' +
            '<label>Ключевые слова (через запятую)' +
            '<input type="text" class="draxter-faq-kw" value="' + escapeAttr(data.keywords) + '" placeholder="доставка, город, срок">' +
            '</label>' +
            '<label>Заголовок' +
            '<input type="text" class="draxter-faq-title" value="' + escapeAttr(data.title) + '" placeholder="Доставка">' +
            '</label>' +
            '<label>Текст ответа для нейросети' +
            '<textarea class="draxter-faq-body" rows="4" placeholder="2–5 предложений с фактами">' + escapeText(data.body) + '</textarea>' +
            '</label>';
        blocksEl.appendChild(el);
        bindBlock(el);
        renumberBlocks();
        syncRaw();
    }

    function escapeAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function escapeText(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
    }

    var initial = parseRaw(rawEl.value);
    if (initial.length) {
        initial.forEach(addBlock);
    } else {
        addBlock({ keywords: '', title: '', body: '' });
    }

    document.getElementById('draxter-faq-add-block').addEventListener('click', function () {
        addBlock({});
    });

    document.getElementById('draxter-faq-add-template').addEventListener('click', function () {
        var key = (document.getElementById('draxter-faq-template-select') || {}).value;
        if (!key || !FAQ_TEMPLATES[key]) return;
        addBlock(FAQ_TEMPLATES[key]);
    });

    var mainForm = rawEl.closest('form');
    if (mainForm) {
        mainForm.addEventListener('submit', syncRaw);
    }

    window.draxterFaqSyncRaw = syncRaw;
})();
</script>

<script>
(function () {
    var btn = document.getElementById('draxter-faq-preview-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (window.draxterFaqSyncRaw) window.draxterFaqSyncRaw();
        if (window.draxterPromptSyncRaw) window.draxterPromptSyncRaw();
        var q = document.getElementById('draxter-faq-question');
        var out = document.getElementById('draxter-faq-preview-out');
        var rawEl = document.getElementById('draxter-faq-knowledge-raw');
        var promptRawEl = document.getElementById('draxter-prompt-blocks-raw');
        var introEl = document.querySelector('[name="PROMPT_FAQ_INTRO"]');
        var withAiEl = document.getElementById('draxter-faq-with-ai');
        if (!q || !out) return;
        out.style.display = 'block';
        out.textContent = (withAiEl && withAiEl.checked) ? 'Загрузка… запрос к нейросети' : 'Загрузка…';
        var fd = new FormData();
        fd.append('draxter_faq_preview', 'Y');
        fd.append('question', q.value);
        if (withAiEl && withAiEl.checked) fd.append('with_ai', 'Y');
        if (rawEl) fd.append('faq_knowledge', rawEl.value);
        if (promptRawEl) fd.append('prompt_custom_blocks', promptRawEl.value);
        if (introEl) fd.append('faq_intro', introEl.value);
        fd.append('sessid', '<?= bitrix_sessid() ?>');
        fetch('<?= CUtil::JSEscape($APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&lang=' . LANGUAGE_ID . '&site_id=' . urlencode($siteId)) ?>', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.text().then(function (text) {
                if (!text) throw new Error('Пустой ответ сервера');
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(text.slice(0, 200) || 'Некорректный ответ сервера');
                }
            });
        }).then(function (data) {
            if (data.error) {
                out.textContent = data.error;
                return;
            }
            var lines = [];
            if (data.matchedCustom && data.matchedCustom.length) {
                lines.push('Свои промпты (по вопросу) — приоритет над FAQ:');
                data.matchedCustom.forEach(function (m) {
                    lines.push('');
                    lines.push('▸ ' + (m.title || '(без названия)'));
                    if (m.phrase) lines.push('  Пример: ' + m.phrase);
                    if (m.body) lines.push('  Текст: ' + m.body);
                });
                lines.push('');
            }
            lines.push('Подобранные блоки FAQ:');
            (data.matched || []).forEach(function (m) {
                lines.push('');
                lines.push('▸ ' + (m.title || '(без заголовка)'));
                if (m.keywords && m.keywords.length) {
                    lines.push('  Ключевые слова: ' + m.keywords.join(', '));
                }
                if (m.body) lines.push('  Текст: ' + m.body);
            });
            lines.push('', '--- Пример из справочника (без нейросети) ---', data.draftAnswer || '—');
            if (data.aiAnswer) {
                lines.push('', '--- Ответ нейросети ---', data.aiAnswer);
            } else if (data.aiError) {
                lines.push('', '--- Ответ нейросети ---', 'Ошибка: ' + data.aiError);
            }
            lines.push('', '--- Фрагмент для промпта ---', data.promptSection || '');
            out.textContent = lines.join('\n');
        }).catch(function (e) {
            out.textContent = e.message || 'Ошибка запроса';
        });
    });
})();
</script>
<script>
(function () {
    function pickTabId() {
        try {
            var q = new URLSearchParams(window.location.search);
            var fromQuery = q.get('tabControl_active_tab');
            if (fromQuery) {
                return fromQuery;
            }
        } catch (e) {}
        if (window.location.hash && window.location.hash.length > 1) {
            return window.location.hash.substring(1);
        }
        return '';
    }
    function activateTab() {
        var id = pickTabId();
        if (!id || !window.tabControl || typeof tabControl.SelectTab !== 'function') {
            return;
        }
        try {
            tabControl.SelectTab(id);
        } catch (e) {}
        if (id === 'edit_stats') {
            var panel = document.getElementById('draxter-stats-session');
            if (panel) {
                setTimeout(function () {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 120);
            }
        }
    }
    if (window.BX && typeof BX.ready === 'function') {
        BX.ready(activateTab);
    } else {
        document.addEventListener('DOMContentLoaded', activateTab);
    }
})();
</script>
