<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Draxter\Aichat\AdminStats;
use Draxter\Aichat\ApiErrorLog;
use Draxter\Aichat\Bitrix24Lead;
use Draxter\Aichat\Catalog;
use Draxter\Aichat\CrmFieldMapper;
use Draxter\Aichat\CustomProviders;
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
    'VOICE_STT_PROVIDER', 'VOICE_GEMINI_STT_MODEL', 'VOICE_TTS_PROVIDER',
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
    $draxterAdminRedirect('purged=1');
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
$logFilter = trim((string)$request->get('log_filter'));
$logFile = trim((string)$request->get('log_file'));
if ($logFile === '' && ApiErrorLog::listLogFiles() !== []) {
    $logFile = ApiErrorLog::listLogFiles()[0];
}
$logLines = $logFile !== '' ? ApiErrorLog::tailLines($logFile, 200, $logFilter !== '' ? $logFilter : null) : [];
$viewSessionId = trim((string)$request->get('view_session'));
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
</style>
<?php if ($request->get('imported') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">
    Настройки импортированы из <code><?= htmlspecialcharsbx($configPath) ?></code>
</div>
<?php endif; ?>
<?php if ($request->get('saved') === '1'): ?>
<div class="draxter-aichat-flash draxter-aichat-flash--ok">Сохранено.</div>
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
<form method="get" style="margin-bottom:12px">
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
        <div class="adm-info-message-wrap draxter-ai-provider-guide" style="max-width:720px;margin:0">
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
    <tr><td colspan="2"><em>Плейсхолдеры в текстах: {shop_name}, {brand}</em></td></tr>
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
        <div class="adm-info-message-wrap" style="max-width:560px;margin-top:8px">
            <p style="margin:0">Каждый блок — отдельная инструкция. Плейсхолдеры: <code>{shop_name}</code>, <code>{brand}</code>. Режим «По вопросу клиента» — промпт добавится, если сообщение похоже на указанный пример вопроса.</p>
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
        <div class="adm-info-message-wrap" style="max-width:560px;margin-top:8px">
            <strong>Как это работает</strong>
            <p style="margin:0">Каждый блок — отдельная тема. Заполните ключевые слова (через запятую), заголовок и текст ответа. Кнопка «+ Добавить блок» создаёт пустой блок с нужными полями.</p>
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
        <pre id="draxter-faq-preview-out" class="draxter-aichat-opt-textarea" style="white-space:pre-wrap;margin-top:8px;background:#f5f5f5;padding:8px;display:none"></pre>
    </td></tr>
    <?php draxter_aichat_tab_help('prompts'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr><td colspan="2">
        <div class="adm-info-message-wrap draxter-intent-intro">
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
    <tr><td>Голосовой ввод</td><td><input type="checkbox" name="VOICE_ENABLED" value="Y" <?= draxter_aichat_opt('VOICE_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Отвечать голосом</td><td><input type="checkbox" name="VOICE_REPLY_ENABLED" value="Y" <?= draxter_aichat_opt('VOICE_REPLY_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>TTS провайдер</td><td>
        <input type="hidden" name="VOICE_TTS_PROVIDER" value="browser">
        Браузер (speechSynthesis)
    </td></tr>
    <tr><td>STT провайдер</td><td>
        <select name="VOICE_STT_PROVIDER">
            <?php foreach (['auto' => 'auto (Gemini)', 'gemini' => 'gemini'] as $p => $label): ?>
                <option value="<?= $p ?>" <?= draxter_aichat_opt('VOICE_STT_PROVIDER', 'auto') === $p ? 'selected' : '' ?>><?= htmlspecialcharsbx($label) ?></option>
            <?php endforeach; ?>
        </select>
    </td></tr>
    <tr><td>Gemini STT модель</td><td><input type="text" name="VOICE_GEMINI_STT_MODEL" value="<?= draxter_aichat_opt('VOICE_GEMINI_STT_MODEL') ?>" size="28" placeholder="пусто = GEMINI_MODEL"></td></tr>
    <tr><td>Макс. длительность записи (с)</td><td><input type="text" name="VOICE_MAX_SECONDS" value="<?= draxter_aichat_opt('VOICE_MAX_SECONDS', '60') ?>" size="6"></td></tr>
    <tr><td>Макс. символов для TTS</td><td><input type="text" name="VOICE_TTS_MAX_CHARS" value="<?= draxter_aichat_opt('VOICE_TTS_MAX_CHARS', '1500') ?>" size="6"></td></tr>
    <tr><td>Скорость озвучки</td><td><input type="text" name="VOICE_TTS_RATE" value="<?= draxter_aichat_opt('VOICE_TTS_RATE', '1.25') ?>" size="6" placeholder="1.25"></td></tr>
    <?php draxter_aichat_tab_help('voice'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr><td>Источник</td><td>
        <select name="CATALOG_SOURCE">
            <option value="yml_url" <?= draxter_aichat_opt('CATALOG_SOURCE', 'yml_url') === 'yml_url' ? 'selected' : '' ?>>YML по URL</option>
            <option value="yml_file" <?= draxter_aichat_opt('CATALOG_SOURCE') === 'yml_file' ? 'selected' : '' ?>>YML файл</option>
            <option value="iblock" <?= draxter_aichat_opt('CATALOG_SOURCE') === 'iblock' ? 'selected' : '' ?>>Инфоблок</option>
        </select>
    </td></tr>
    <tr><td>CATALOG_URL</td><td><input type="text" name="CATALOG_URL" value="<?= draxter_aichat_opt('CATALOG_URL', '/bitrix/catalog_export/catalog.xml') ?>" size="70"></td></tr>
    <tr><td>CATALOG_PATH</td><td><input type="text" name="CATALOG_PATH" value="<?= draxter_aichat_opt('CATALOG_PATH') ?>" size="70"></td></tr>
    <tr><td>Инфоблок ID</td><td>
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
    </td></tr>
    <tr><td>Профиль каталога</td><td>
        <select name="CATALOG_PROFILE">
            <?php foreach (['auto', 'garden', 'full'] as $p): ?>
                <option value="<?= $p ?>" <?= draxter_aichat_opt('CATALOG_PROFILE', 'auto') === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
        </select>
    </td></tr>
    <tr><td>Кэш (мин)</td><td><input type="text" name="CATALOG_REFRESH_MINUTES" value="<?= draxter_aichat_opt('CATALOG_REFRESH_MINUTES', '60') ?>" size="10"></td></tr>
    <tr><td colspan="2"><label><input type="checkbox" name="refresh_catalog" value="Y"> Сбросить кэш при сохранении</label></td></tr>
    <?php draxter_aichat_tab_help('catalog'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr><td>Название магазина</td><td><input type="text" name="SHOP_NAME" value="<?= draxter_aichat_opt('SHOP_NAME', 'Магазин') ?>" size="40"></td></tr>
    <tr><td>Бренд</td><td><input type="text" name="SHOP_BRAND" value="<?= draxter_aichat_opt('SHOP_BRAND') ?>" size="40" placeholder="пусто = название"></td></tr>
    <tr><td>SITE_URL</td><td><input type="text" name="SITE_URL" value="<?= draxter_aichat_opt('SITE_URL') ?>" size="50"></td></tr>
    <tr><td>Специализация</td><td><input type="text" name="SHOP_SPECIALIZATION" value="<?= draxter_aichat_opt('SHOP_SPECIALIZATION') ?>" size="70"></td></tr>
    <tr><td>Лимит запросов/мин (IP)</td><td><input type="text" name="RATE_LIMIT_PER_MINUTE" value="<?= draxter_aichat_opt('RATE_LIMIT_PER_MINUTE', '60') ?>" size="6"> (0 = без лимита)</td></tr>
    <tr><td colspan="2"><strong>Каналы лидов</strong></td></tr>
    <tr><td colspan="2"><em>Можно включить несколько каналов одновременно. Ошибка одного канала не блокирует остальные.</em></td></tr>
    <tr><td colspan="2"><strong>Bitrix24 (облако)</strong></td></tr>
    <tr><td>Создавать лиды</td><td><input type="checkbox" name="BITRIX24_LEAD_ENABLED" value="Y" <?= draxter_aichat_opt('BITRIX24_LEAD_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Webhook</td><td><input type="text" name="BITRIX24_WEBHOOK_URL" value="<?= draxter_aichat_opt('BITRIX24_WEBHOOK_URL') ?>" size="70"></td></tr>
    <tr><td>Ответственный ID</td><td><input type="text" name="BITRIX24_ASSIGNED_ID" value="<?= draxter_aichat_opt('BITRIX24_ASSIGNED_ID', '1') ?>" size="10"></td></tr>
    <tr><td>SOURCE_ID (запасной)</td><td><input type="text" name="BITRIX24_SOURCE_ID" value="<?= draxter_aichat_opt('BITRIX24_SOURCE_ID', 'aichat') ?>" size="20" placeholder="UC_…"> <span class="adm-info-message-wrap">Используется, если в CRM нет источника с именем из поля ниже. Поле «Источник» в лиде — <strong>название</strong> из справочника CRM → Источники, не домен.</span></td></tr>
    <tr><td>Имя источника в CRM</td><td><input type="text" name="BITRIX24_SOURCE_LABEL" value="<?= draxter_aichat_opt('BITRIX24_SOURCE_LABEL', 'aichat') ?>" size="20" placeholder="aichat"> <span class="adm-info-message-wrap">Модуль ищет источник с таким <strong>названием</strong> (например <code>aichat</code>). Если у кода <code>UC_…</code> название «draxter.ru» — переименуйте в CRM или создайте источник «aichat».</span></td></tr>
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
    <tr><td colspan="2"><strong>CRM сайта (модуль crm)</strong></td></tr>
    <tr><td>Создавать лид в CRM сайта</td><td><input type="checkbox" name="CRM_LOCAL_ENABLED" value="Y" <?= draxter_aichat_opt('CRM_LOCAL_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Маппинг локального CRM (JSON)</td><td>
        <textarea name="CRM_LOCAL_FIELD_MAP" rows="8" cols="70" style="font-family:monospace;font-size:12px"><?= draxter_aichat_optarea('CRM_LOCAL_FIELD_MAP') ?></textarea>
        <button type="submit" name="local_map_template" value="Y" class="adm-btn">Шаблон локального CRM</button>
    </td></tr>
    <tr><td colspan="2"><strong>Email</strong></td></tr>
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
    <tr><td colspan="2">
        <a href="?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>&stats_days=7">7 дней</a>
        | <a href="?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>&stats_days=30">30 дней</a>
    </td></tr>
    <tr><td>Сессий</td><td><?= (int)$statsData['sessions'] ?></td></tr>
    <tr><td>Сообщений (ходов)</td><td><?= (int)$statsData['messages'] ?></td></tr>
    <tr><td>Ошибок API в логах</td><td><?= (int)$statsData['errors'] ?></td></tr>
    <tr><td>Лидов</td><td><?= (int)$statsData['leads'] ?></td></tr>
    <tr><td>Топ фраз пользователя</td><td>
        <?php if ($statsData['topPhrases'] === []): ?>
            <em>Нет данных</em>
        <?php else: ?>
            <ol><?php foreach ($statsData['topPhrases'] as $row): ?>
                <li><?= htmlspecialcharsbx($row['phrase']) ?> (<?= (int)$row['count'] ?>)</li>
            <?php endforeach; ?></ol>
        <?php endif; ?>
    </td></tr>
    <tr><td>Последние диалоги</td><td>
        <table class="internal" style="width:100%">
            <tr><th>Дата</th><th>Сессия</th><th>Тел.</th><th>Ходов</th><th></th></tr>
            <?php foreach ($statsData['recent'] as $row): ?>
                <tr>
                    <td><?= htmlspecialcharsbx($row['date']) ?></td>
                    <td><code><?= htmlspecialcharsbx($row['sessionId']) ?></code></td>
                    <td><?= htmlspecialcharsbx($row['phone'] ?? '—') ?></td>
                    <td><?= (int)$row['turns'] ?><?= !empty($row['error']) ? ' ⚠' : '' ?></td>
                    <td><a href="?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>&view_session=<?= urlencode($row['sessionId']) ?>#edit_logs">лог</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </td></tr>
    <?php draxter_aichat_tab_help('stats'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr><td>Включить логи сессий</td><td><input type="checkbox" name="CHAT_LOG_ENABLED" value="Y" <?= draxter_aichat_opt('CHAT_LOG_ENABLED', 'Y') === 'Y' ? 'checked' : '' ?>></td></tr>
    <tr><td>Папка логов</td><td><input type="text" name="CHAT_LOG_DIR" value="<?= draxter_aichat_opt('CHAT_LOG_DIR') ?>" size="60" placeholder="/upload/draxter_aichat_logs"></td></tr>
    <tr><td>Лог API</td><td>
        <p>
            <?php foreach (ApiErrorLog::listLogFiles() as $f): ?>
                <a href="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>&log_file=<?= urlencode($f) ?><?= $logFilter !== '' ? '&log_filter=' . urlencode($logFilter) : '' ?>#edit_logs"><?= htmlspecialcharsbx(basename($f)) ?></a>
                &nbsp;
            <?php endforeach; ?>
        </p>
        <p>Фильтр в URL: <code>?log_filter=error</code> (подстрока в строке)</p>
        <pre style="max-height:400px;overflow:auto;background:#1e1e1e;color:#ddd;padding:8px;font-size:11px"><?php
            foreach ($logLines as $line) {
                echo htmlspecialcharsbx($line) . "\n";
            }
        ?></pre>
        <p><em>Очистка старых api-логов — форма ниже страницы настроек.</em></p>
    </td></tr>
    <?php if ($viewSessionId !== ''): ?>
    <tr><td>Сессия <?= htmlspecialcharsbx($viewSessionId) ?></td><td>
        <pre style="max-height:500px;overflow:auto;white-space:pre-wrap;background:#f8f8f8;padding:8px"><?= htmlspecialcharsbx(AdminStats::formatSessionForAdmin($viewSessionId)) ?></pre>
    </td></tr>
    <?php endif; ?>
    <?php draxter_aichat_tab_help('logs'); ?>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <input type="submit" name="reset_defaults" value="Сбросить к умолчанию" class="adm-btn" onclick="return confirm('Сбросить все настройки этого сайта?');">
    <?php $tabControl->End(); ?>
</form>

<form method="post" style="margin-top:16px" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>&site_id=<?= urlencode($siteId) ?>" onsubmit="return confirm('Удалить старые api-*.log?');">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>">
    <input type="hidden" name="purge_api_logs" value="Y">
    Удалить api-логи старше <input type="number" name="purge_days" value="30" size="4" min="1"> дней
    <input type="submit" value="Очистить api-логи" class="adm-btn">
</form>

<div class="adm-info-message-wrap" style="margin-top:16px">
    <div class="adm-info-message">
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
