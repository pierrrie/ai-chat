<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */
/** @var CBitrixComponentTemplate $this */
$shopName = htmlspecialcharsbx($arResult['SHOP_NAME']);
$agentName = htmlspecialcharsbx($arResult['AGENT_NAME'] ?? $shopName);
$embedded = $arResult['EMBEDDED'] === 'Y';
$ajaxUrl = htmlspecialcharsbx($arResult['AJAX_URL']);
$avatarUrl = htmlspecialcharsbx($arResult['AVATAR_URL'] ?? '');
$accent = htmlspecialcharsbx($arResult['ACCENT_COLOR'] ?? '#2563eb');
$fabColor = htmlspecialcharsbx($arResult['FAB_COLOR'] ?? $accent);
$fabTitle = htmlspecialcharsbx($arResult['FAB_TITLE'] ?? 'Чат');
$fabSubtitle = htmlspecialcharsbx($arResult['FAB_SUBTITLE'] ?? 'Спросить о товаре');
$fabAlignH = htmlspecialcharsbx($arResult['FAB_ALIGN_H'] ?? 'right');
$fabAlignV = htmlspecialcharsbx($arResult['FAB_ALIGN_V'] ?? 'bottom');
$fabOffsetH = htmlspecialcharsbx($arResult['FAB_OFFSET_H'] ?? '24');
$fabOffsetV = htmlspecialcharsbx($arResult['FAB_OFFSET_V'] ?? '24');
$assetVer = '2.2.5';
$cssUrl = $templateFolder . '/style.css';
$jsUrl = $templateFolder . '/script.js';
?>
<link rel="stylesheet" href="<?= htmlspecialcharsbx($cssUrl) ?>?v=<?= htmlspecialcharsbx($assetVer) ?>">
<div
    class="draxter-aichat-root"
    style="--drax-accent: <?= $accent ?>; --drax-fab-bg: <?= $fabColor ?>; --drax-fab-h-offset: <?= $fabOffsetH ?>px; --drax-fab-v-offset: <?= $fabOffsetV ?>px;"
    data-fab-h="<?= $fabAlignH ?>"
    data-fab-v="<?= $fabAlignV ?>"
    data-shop-name="<?= $shopName ?>"
    data-agent-name="<?= $agentName ?>"
    data-fab-title="<?= $fabTitle ?>"
    data-fab-subtitle="<?= $fabSubtitle ?>"
    data-status-text="<?= htmlspecialcharsbx($arResult['STATUS_TEXT'] ?? 'Онлайн • отвечает сразу') ?>"
    data-show-online="<?= htmlspecialcharsbx($arResult['SHOW_ONLINE'] ?? '1') ?>"
    data-welcome="<?= htmlspecialcharsbx($arResult['WELCOME']) ?>"
    data-ajax-url="<?= $ajaxUrl ?>"
    data-embedded="<?= $embedded ? '1' : '0' ?>"
    data-avatar-url="<?= $avatarUrl ?>"
    data-voice-enabled="<?= htmlspecialcharsbx($arResult['VOICE_ENABLED'] ?? '0') ?>"
    data-voice-reply="<?= htmlspecialcharsbx($arResult['VOICE_REPLY'] ?? '0') ?>"
    data-voice-max-seconds="<?= htmlspecialcharsbx($arResult['VOICE_MAX_SECONDS'] ?? '60') ?>"
    data-voice-tts-max-chars="<?= htmlspecialcharsbx($arResult['VOICE_TTS_MAX_CHARS'] ?? '1500') ?>"
    data-voice-tts-rate="<?= htmlspecialcharsbx($arResult['VOICE_TTS_RATE'] ?? '1.25') ?>"
    data-ym-enabled="<?= htmlspecialcharsbx($arResult['YM_ENABLED'] ?? '0') ?>"
    data-ym-counter="<?= htmlspecialcharsbx($arResult['YM_COUNTER'] ?? '') ?>"
    data-ym-goal="<?= htmlspecialcharsbx($arResult['YM_GOAL'] ?? 'aibot') ?>"
></div>
<script src="<?= htmlspecialcharsbx($jsUrl) ?>?v=<?= htmlspecialcharsbx($assetVer) ?>" defer></script>
